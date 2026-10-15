<?php
/**
 * Student payments ledger.
 *
 * Every payment a student makes is one row in `student_payments`. Fees come
 * from the existing price + discount setup (department_prices /
 * student_discounts); this file adds the "money received" side and works out
 * totals and remaining balances from it.
 *
 * Rules the whole feature relies on:
 *  - A payment belongs to one student AND one grade (fees are priced per grade,
 *    so a promoted student starts a fresh balance for the new grade).
 *  - Payments are never deleted. A mistake is VOIDED (with a reason, who did it
 *    and when) so the books always keep a full audit trail. Voided payments are
 *    ignored in every total.
 *  - Receipt numbers run 1, 2, 3... separately for each institute.
 *  - The student's name is copied onto the payment, so the money history
 *    survives even if a student record is later removed.
 */

const PAYMENT_METHODS = [
    'cash'          => 'Cash',
    'bank_transfer' => 'Bank transfer',
    'card'          => 'Card',
    'cheque'        => 'Cheque',
    'other'         => 'Other',
];

/** Creates the payments table the first time any accounting page needs it, so
 *  upgrading just means uploading the new files (database/institute_system.sql
 *  already contains the same table for fresh installs). */
function ensure_payments_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `student_payments` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `institute_id` int NOT NULL,
          `receipt_no` int unsigned NOT NULL,
          `student_id` int NOT NULL,
          `student_name` varchar(160) NOT NULL,
          `grade` varchar(20) NOT NULL,
          `amount` decimal(12,2) NOT NULL,
          `paid_at` date NOT NULL,
          `method` varchar(20) NOT NULL DEFAULT 'cash',
          `reference` varchar(100) DEFAULT NULL,
          `note` varchar(255) DEFAULT NULL,
          `recorded_by_role` varchar(20) NOT NULL,
          `recorded_by_id` int NOT NULL,
          `recorded_by_name` varchar(100) NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `is_void` tinyint(1) NOT NULL DEFAULT '0',
          `void_reason` varchar(255) DEFAULT NULL,
          `voided_at` timestamp NULL DEFAULT NULL,
          `voided_by_name` varchar(100) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_receipt` (`institute_id`, `receipt_no`),
          KEY `idx_student_grade` (`student_id`, `grade`),
          KEY `idx_institute_date` (`institute_id`, `paid_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** 1500000 -> "1,500,000"; 1500.5 -> "1,500.50". Whole amounts drop the ".00" so big
 *  numbers stay readable on cards and tables. */
function format_money_auto(float $amount): string
{
    return abs($amount - round($amount)) < 0.005
        ? number_format($amount, 0)
        : number_format($amount, 2);
}

/** "2026-10-04" -> "04 Oct 2026". */
function format_pay_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : $date;
}

/** Only accepts a real calendar date in YYYY-MM-DD form. */
function valid_pay_date(?string $date): bool
{
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

/* ------------------------------ fees per grade ------------------------------ */

/** What one student owes for one specific grade: price, discount and final fee. */
function student_grade_fee(mysqli $conn, int $studentId, int $departmentId, string $grade): array
{
    $stmt = $conn->prepare("SELECT price FROM department_prices WHERE department_id = ? AND grade = ?");
    $stmt->bind_param('is', $departmentId, $grade);
    $stmt->execute();
    $priceRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT discount_type, discount_value FROM student_discounts WHERE student_id = ? AND grade = ?");
    $stmt->bind_param('is', $studentId, $grade);
    $stmt->execute();
    $discount = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    $base = $priceRow ? (float)$priceRow['price'] : 0.0;
    $fee = apply_discount($base, $discount);
    return [
        'priced'          => $base > 0,
        'base'            => $base,
        'discount_amount' => round($base - $fee, 2),
        'fee'             => $fee,
    ];
}

/** Total of non-void payments for one student + grade. */
function student_paid_for_grade(mysqli $conn, int $studentId, string $grade): float
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS paid FROM student_payments
         WHERE student_id = ? AND grade = ? AND is_void = 0"
    );
    $stmt->bind_param('is', $studentId, $grade);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return round($paid, 2);
}

/** Paid / unpaid / etc. from the numbers. $priced=false means no price is set for the grade. */
function payment_status(bool $priced, float $fee, float $paid): string
{
    if (!$priced) {
        return 'unpriced';
    }
    $remaining = round($fee - $paid, 2);
    if ($remaining < -0.004) {
        return 'overpaid';
    }
    if ($remaining <= 0.004) {
        return 'paid';
    }
    return $paid > 0 ? 'partial' : 'unpaid';
}

function payment_status_meta(string $status): array
{
    $map = [
        'paid'     => ['Paid',      'success'],
        'partial'  => ['Partial',   'warning'],
        'unpaid'   => ['Unpaid',    'danger'],
        'overpaid' => ['Overpaid',  'info'],
        'unpriced' => ['No price',  'secondary'],
    ];
    return $map[$status] ?? [ucfirst($status), 'secondary'];
}

/* -------------------------------- recording -------------------------------- */

/** Records one payment and returns [paymentId, receiptNo]. The caller has already validated
 *  the input; this just does the insert, handing out the next receipt number safely. */
function add_payment(
    mysqli $conn, int $instituteId, array $student, string $grade, float $amount,
    string $paidAt, string $method, ?string $reference, ?string $note,
    string $actorRole, int $actorId, string $actorName
): array {
    $name = student_full_name($student);
    $studentId = (int)$student['id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT COALESCE(MAX(receipt_no), 0) + 1 AS next_no FROM student_payments WHERE institute_id = ? FOR UPDATE"
        );
        $stmt->bind_param('i', $instituteId);
        $stmt->execute();
        $receiptNo = (int)$stmt->get_result()->fetch_assoc()['next_no'];
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO student_payments
               (institute_id, receipt_no, student_id, student_name, grade, amount, paid_at, method,
                reference, note, recorded_by_role, recorded_by_id, recorded_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiissdsssssis',
            $instituteId, $receiptNo, $studentId, $name, $grade, $amount, $paidAt, $method,
            $reference, $note, $actorRole, $actorId, $actorName
        );
        $stmt->execute();
        $paymentId = (int)$conn->insert_id;
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return [$paymentId, $receiptNo];
}

/** Marks a payment as void (kept on record, excluded from all totals). */
function void_payment(mysqli $conn, int $paymentId, int $instituteId, string $reason, string $actorName): bool
{
    $stmt = $conn->prepare(
        "UPDATE student_payments
         SET is_void = 1, void_reason = ?, voided_at = NOW(), voided_by_name = ?
         WHERE id = ? AND institute_id = ? AND is_void = 0"
    );
    $stmt->bind_param('ssii', $reason, $actorName, $paymentId, $instituteId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

function get_payment(mysqli $conn, int $paymentId, int $instituteId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM student_payments WHERE id = ? AND institute_id = ?");
    $stmt->bind_param('ii', $paymentId, $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/* ------------------------------- balances -------------------------------- */

/** Every student (same filters as the pricing worksheet) with what they've paid for their
 *  CURRENT grade and what is still owed. $status filters by paid/partial/unpaid/overpaid/unpriced. */
function students_with_balances(
    mysqli $conn, int $instituteId, ?int $departmentId = null, ?string $grade = null,
    string $search = '', string $status = ''
): array {
    $rows = students_with_pricing($conn, $instituteId, $departmentId, $grade, $search);

    $stmt = $conn->prepare(
        "SELECT student_id, grade, SUM(amount) AS paid, COUNT(*) AS n, MAX(paid_at) AS last_paid
         FROM student_payments WHERE institute_id = ? AND is_void = 0
         GROUP BY student_id, grade"
    );
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $paidMap = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
        $paidMap[$p['student_id'] . '|' . $p['grade']] = $p;
    }
    $stmt->close();

    // Money still owed for EARLIER grades (a student who was promoted with an unpaid balance).
    // Only grades the student actually paid something toward are considered, since that's the
    // only record we have of them having been enrolled in that grade.
    $otherGradesByStudent = [];
    foreach ($paidMap as $key => $p) {
        $otherGradesByStudent[(int)$p['student_id']][] = $p;
    }
    $priceMap = [];
    $stmt = $conn->prepare(
        "SELECT dp.department_id, dp.grade, dp.price FROM department_prices dp
         JOIN departments d ON d.id = dp.department_id WHERE d.institute_id = ?"
    );
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $pr) {
        $priceMap[$pr['department_id'] . '|' . $pr['grade']] = (float)$pr['price'];
    }
    $stmt->close();
    $needDiscounts = [];
    foreach ($rows as $row) {
        foreach ($otherGradesByStudent[(int)$row['id']] ?? [] as $p) {
            if ($p['grade'] !== $row['grade']) {
                $needDiscounts[] = (int)$row['id'];
                break;
            }
        }
    }
    $discountMap = $needDiscounts ? student_discounts_map($conn, $needDiscounts) : [];

    $out = [];
    foreach ($rows as $row) {
        $arrears = 0.0;
        foreach ($otherGradesByStudent[(int)$row['id']] ?? [] as $op) {
            if ($op['grade'] === $row['grade']) {
                continue;
            }
            $base = $priceMap[$row['department_id'] . '|' . $op['grade']] ?? 0.0;
            if ($base <= 0) {
                continue;
            }
            $disc = $discountMap[(int)$row['id']][$op['grade']] ?? null;
            $left = round(apply_discount($base, $disc) - (float)$op['paid'], 2);
            if ($left > 0.004) {
                $arrears += $left;
            }
        }
        $row['previous_remaining'] = round($arrears, 2);
        $p = $paidMap[$row['id'] . '|' . $row['grade']] ?? null;
        $paid = $p ? round((float)$p['paid'], 2) : 0.0;
        $row['paid'] = $paid;
        $row['remaining'] = round($row['final_price'] - $paid, 2);
        $row['payments_count'] = $p ? (int)$p['n'] : 0;
        $row['last_paid'] = $p['last_paid'] ?? null;
        $row['status'] = payment_status($row['base_price'] > 0, $row['final_price'], $paid);
        $row['percent_paid'] = $row['final_price'] > 0
            ? min(100, (int)round($paid / $row['final_price'] * 100))
            : ($row['status'] === 'paid' ? 100 : 0);
        if ($status === '' || $row['status'] === $status) {
            $out[] = $row;
        }
    }
    return $out;
}

/** Headline numbers for the Payments page. "Expected / collected / remaining" are for each
 *  student's current grade (the fee they are being charged right now). "Remaining" also includes
 *  anything still owed for earlier grades; "collected all time" counts every grade. */
function payments_summary(mysqli $conn, int $instituteId): array
{
    $rows = students_with_balances($conn, $instituteId);
    $s = [
        'students' => count($rows), 'expected' => 0.0, 'collected' => 0.0, 'remaining' => 0.0, 'credit' => 0.0, 'arrears' => 0.0,
        'counts' => ['paid' => 0, 'partial' => 0, 'unpaid' => 0, 'overpaid' => 0, 'unpriced' => 0],
    ];
    foreach ($rows as $r) {
        $s['expected'] += $r['final_price'];
        $s['collected'] += $r['paid'];
        if ($r['remaining'] > 0) {
            $s['remaining'] += $r['remaining'];
        } else {
            $s['credit'] += -$r['remaining'];
        }
        $s['remaining'] += $r['previous_remaining'];
        $s['arrears'] += $r['previous_remaining'];
        $s['counts'][$r['status']]++;
    }
    $s['rate'] = $s['expected'] > 0 ? min(100, (int)round(min($s['collected'], $s['expected']) / $s['expected'] * 100)) : 0;

    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS all_time,
                COALESCE(SUM(CASE WHEN paid_at = ? THEN amount END), 0) AS today,
                COALESCE(SUM(CASE WHEN paid_at >= ? THEN amount END), 0) AS month,
                COUNT(*) AS n
         FROM student_payments WHERE institute_id = ? AND is_void = 0"
    );
    $stmt->bind_param('ssi', $today, $monthStart, $instituteId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $s['all_time'] = (float)$t['all_time'];
    $s['today'] = (float)$t['today'];
    $s['month'] = (float)$t['month'];
    $s['payments_count'] = (int)$t['n'];
    return $s;
}

/** One student's full statement: a row per grade (current grade + any grade they've paid for),
 *  overall totals, and every payment (including voided ones, flagged). */
function student_statement(mysqli $conn, array $student): array
{
    $studentId = (int)$student['id'];
    $deptId = (int)$student['department_id'];

    $stmt = $conn->prepare("SELECT * FROM student_payments WHERE student_id = ? ORDER BY paid_at DESC, id DESC");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $grades = [$student['grade']];
    foreach ($payments as $p) {
        if (!$p['is_void'] && !in_array($p['grade'], $grades, true)) {
            $grades[] = $p['grade'];
        }
    }
    usort($grades, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

    $totals = ['fee' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'discount' => 0.0];
    $rows = [];
    foreach ($grades as $g) {
        $fee = student_grade_fee($conn, $studentId, $deptId, $g);
        $paid = student_paid_for_grade($conn, $studentId, $g);
        $remaining = round($fee['fee'] - $paid, 2);
        $rows[] = [
            'grade' => $g, 'is_current' => $g === $student['grade'], 'priced' => $fee['priced'],
            'base' => $fee['base'], 'discount' => $fee['discount_amount'], 'fee' => $fee['fee'],
            'paid' => $paid, 'remaining' => $remaining,
            'status' => payment_status($fee['priced'], $fee['fee'], $paid),
            'percent' => $fee['fee'] > 0 ? min(100, (int)round($paid / $fee['fee'] * 100)) : ($remaining <= 0 && $fee['priced'] ? 100 : 0),
        ];
        $totals['fee'] += $fee['fee'];
        $totals['paid'] += $paid;
        $totals['remaining'] += $remaining;
        $totals['discount'] += $fee['discount_amount'];
    }
    $totals['percent'] = $totals['fee'] > 0 ? min(100, (int)round(min($totals['paid'], $totals['fee']) / $totals['fee'] * 100)) : 0;

    return ['grades' => $rows, 'totals' => $totals, 'payments' => $payments];
}

/* -------------------------------- the ledger -------------------------------- */

/** Builds the WHERE clause shared by the ledger list, its totals and the Excel export. */
function payments_ledger_where(int $instituteId, array $f): array
{
    $where = "p.institute_id = ?";
    $types = 'i';
    $params = [$instituteId];

    if (!empty($f['student_id'])) {
        $where .= " AND p.student_id = ?";
        $types .= 'i';
        $params[] = (int)$f['student_id'];
    }
    if (!empty($f['from']) && valid_pay_date($f['from'])) {
        $where .= " AND p.paid_at >= ?";
        $types .= 's';
        $params[] = $f['from'];
    }
    if (!empty($f['to']) && valid_pay_date($f['to'])) {
        $where .= " AND p.paid_at <= ?";
        $types .= 's';
        $params[] = $f['to'];
    }
    if (!empty($f['method']) && isset(PAYMENT_METHODS[$f['method']])) {
        $where .= " AND p.method = ?";
        $types .= 's';
        $params[] = $f['method'];
    }
    if (!empty($f['q'])) {
        $like = '%' . $f['q'] . '%';
        $where .= " AND (p.student_name LIKE ? OR p.reference LIKE ? OR p.receipt_no = ?)";
        $types .= 'ssi';
        $params[] = $like;
        $params[] = $like;
        $params[] = (int)ltrim($f['q'], '#');
    }
    if (empty($f['include_void'])) {
        $where .= " AND p.is_void = 0";
    }
    return [$where, $types, $params];
}

/** A page of ledger rows plus the count and total of what matches (voided payments never add to the total). */
function payments_ledger(mysqli $conn, int $instituteId, array $filters, int $limit = 25, int $offset = 0): array
{
    [$where, $types, $params] = payments_ledger_where($instituteId, $filters);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN p.is_void = 0 THEN p.amount END), 0) AS total
         FROM student_payments p WHERE $where"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $agg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $rows = [];
    if ($limit > 0) {
        $stmt = $conn->prepare("SELECT p.* FROM student_payments p WHERE $where ORDER BY p.paid_at DESC, p.id DESC LIMIT ? OFFSET ?");
        $t2 = $types . 'ii';
        $p2 = array_merge($params, [$limit, $offset]);
        $stmt->bind_param($t2, ...$p2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return ['rows' => $rows, 'count' => (int)$agg['n'], 'total' => (float)$agg['total']];
}

/** Every row matching the filters (for the Excel export). */
function payments_ledger_all(mysqli $conn, int $instituteId, array $filters): array
{
    [$where, $types, $params] = payments_ledger_where($instituteId, $filters);
    $stmt = $conn->prepare("SELECT p.* FROM student_payments p WHERE $where ORDER BY p.paid_at DESC, p.id DESC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Loads a student for the payments pages. Unlike get_student() it uses LEFT JOINs, so a student
 *  whose class section or department is missing still opens (grade/department just show blank). */
function get_student_for_payments(mysqli $conn, int $studentId, int $instituteId): ?array
{
    $stmt = $conn->prepare(
        "SELECT s.*, COALESCE(cs.grade, '') AS grade, COALESCE(cs.class_group, '') AS class_group,
                COALESCE(d.id, 0) AS department_id, COALESCE(d.name, '—') AS department_name
         FROM students s
         LEFT JOIN class_sections cs ON cs.id = s.class_section_id
         LEFT JOIN departments d ON d.id = cs.department_id
         WHERE s.id = ? AND s.institute_id = ?"
    );
    $stmt->bind_param('ii', $studentId, $instituteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}
