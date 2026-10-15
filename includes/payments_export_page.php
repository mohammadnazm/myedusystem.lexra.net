<?php
/**
 * Excel export for the Payments pages (shared by admin and accountant).
 *   ?type=ledger    -> every payment matching the ledger filters
 *   ?type=balances  -> every student's fee / paid / remaining (current grade)
 */
require_once __DIR__ . '/xlsx_writer.php';
ensure_payments_table($conn);

$instituteId = current_institute_id();
$type = ($_GET['type'] ?? 'ledger') === 'balances' ? 'balances' : 'ledger';

if ($type === 'balances') {
    $status = (string)($_GET['status'] ?? '');
    $rows = students_with_balances(
        $conn, $instituteId, (int)($_GET['department_id'] ?? 0) ?: null,
        trim($_GET['grade'] ?? '') ?: null, trim($_GET['q'] ?? ''), $status
    );
    $columns = ['Student', 'Department', 'Grade', 'Group', 'Price', 'Discount', 'Fee', 'Paid', 'Remaining', 'Earlier Grades Owed', 'Status', 'Last Payment'];
    $data = array_map(fn($s) => [
        'Student' => student_full_name($s),
        'Department' => $s['department_name'],
        'Grade' => $s['grade'],
        'Group' => $s['class_group'],
        'Price' => $s['base_price'],
        'Discount' => $s['discount_amount'],
        'Fee' => $s['final_price'],
        'Paid' => $s['paid'],
        'Remaining' => max(0, $s['remaining']),
        'Earlier Grades Owed' => $s['previous_remaining'],
        'Status' => payment_status_meta($s['status'])[0],
        'Last Payment' => $s['last_paid'] ?? '',
    ], $rows);
    $file = 'student-balances-' . date('Y-m-d') . '.xlsx';
    $what = 'student balances';
} else {
    $rows = payments_ledger_all($conn, $instituteId, [
        'q' => trim($_GET['pq'] ?? ''), 'from' => trim($_GET['from'] ?? ''), 'to' => trim($_GET['to'] ?? ''),
        'method' => (string)($_GET['method'] ?? ''), 'include_void' => !empty($_GET['void']),
    ]);
    $columns = ['Receipt No', 'Date', 'Student', 'Grade', 'Method', 'Reference', 'Amount', 'Note', 'Recorded By', 'Status'];
    $data = array_map(fn($p) => [
        'Receipt No' => (int)$p['receipt_no'],
        'Date' => $p['paid_at'],
        'Student' => $p['student_name'],
        'Grade' => $p['grade'],
        'Method' => PAYMENT_METHODS[$p['method']] ?? $p['method'],
        'Reference' => $p['reference'] ?? '',
        'Amount' => (float)$p['amount'],
        'Note' => $p['note'] ?? '',
        'Recorded By' => $p['recorded_by_name'],
        'Status' => $p['is_void'] ? 'Void: ' . $p['void_reason'] : 'Valid',
    ], $rows);
    $file = 'payments-' . date('Y-m-d') . '.xlsx';
    $what = 'payments';
}

log_activity($conn, $instituteId, $role, (int)current_user_id(), (string)current_user_name(), 'Exported ' . count($data) . ' ' . $what . ' to Excel');
send_xlsx_download($file, $columns, $data);
