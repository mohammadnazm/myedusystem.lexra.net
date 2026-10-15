<?php
/**
 * Handles the two payment form actions (record / void) for both the admin and
 * the accountant pages. Call process_payment_post() at the top of a page when
 * the request is a POST; it always redirects and exits.
 */

/** Keeps only known filter keys from a posted query string, so "go back to where I was"
 *  can never be abused to send someone to an arbitrary URL. */
function safe_return_query(string $raw): array
{
    parse_str($raw, $parsed);
    $allowed = ['tab', 'department_id', 'grade', 'q', 'status', 'from', 'to', 'method', 'page', 'void', 'pq'];
    $out = [];
    foreach ($allowed as $key) {
        if (isset($parsed[$key]) && is_scalar($parsed[$key]) && $parsed[$key] !== '') {
            $out[$key] = (string)$parsed[$key];
        }
    }
    return $out;
}

function process_payment_post(mysqli $conn, string $role, int $instituteId): void
{
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $returnTo = ($_POST['return_to'] ?? 'list') === 'student' ? 'student' : 'list';
    $returnQuery = safe_return_query((string)($_POST['return_qs'] ?? ''));
    $studentId = (int)($_POST['student_id'] ?? 0);

    $listUrl = function (array $extra = []) use ($role, $returnQuery): string {
        $qs = http_build_query(array_merge($returnQuery, $extra));
        return base_url($role . '/payments.php') . ($qs ? '?' . $qs : '');
    };
    $studentUrl = function (int $sid, array $extra = []) use ($role): string {
        return base_url($role . '/student_payments.php') . '?' . http_build_query(array_merge(['id' => $sid], $extra));
    };
    $back = function (array $extra = []) use ($returnTo, $studentId, $listUrl, $studentUrl): string {
        return $returnTo === 'student' && $studentId ? $studentUrl($studentId, $extra) : $listUrl($extra);
    };

    if ($action === 'add_payment') {
        $student = $studentId ? get_student_for_payments($conn, $studentId, $instituteId) : null;
        $grade = trim((string)($_POST['grade'] ?? ''));
        $amount = parse_money_input($_POST['amount'] ?? '');
        $paidAt = trim((string)($_POST['paid_at'] ?? ''));
        $method = (string)($_POST['method'] ?? 'cash');
        $reference = trim((string)($_POST['reference'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        $error = null;
        if (!$student) {
            $error = 'Student not found.';
        } elseif ($grade === '') {
            $grade = $student['grade'];
        }

        if (!$error) {
            $allowedGrades = priced_grades_for_department($conn, (int)$student['department_id']);
            $allowedGrades[] = $student['grade'];
            if (!in_array($grade, $allowedGrades, true)) {
                $error = 'That grade is not valid for this student.';
            } elseif ($amount === null || $amount <= 0) {
                $error = 'Enter the amount received as a number greater than zero, e.g. 250000.';
            } elseif (!valid_pay_date($paidAt) || $paidAt > date('Y-m-d')) {
                $error = 'Choose a valid payment date (it cannot be in the future).';
            } elseif (!isset(PAYMENT_METHODS[$method])) {
                $error = 'Choose a payment method.';
            } elseif (mb_strlen($reference) > 100 || mb_strlen($note) > 255) {
                $error = 'The reference or note is too long.';
            } else {
                $fee = student_grade_fee($conn, $studentId, (int)$student['department_id'], $grade);
                $paidSoFar = student_paid_for_grade($conn, $studentId, $grade);
                $remaining = round($fee['fee'] - $paidSoFar, 2);
                if (!$fee['priced']) {
                    $error = "No price is set for grade $grade yet. Set it in Accounting first, then record the payment.";
                } elseif ($amount > $remaining + 0.004) {
                    $error = $remaining <= 0.004
                        ? "Grade $grade is already fully paid."
                        : 'That is more than the remaining balance (' . format_money_auto($remaining) . ') for grade ' . $grade . '.';
                }
            }
        }

        if ($error) {
            flash_set('danger', $error);
            header('Location: ' . $back());
            exit;
        }

        [$paymentId, $receiptNo] = add_payment(
            $conn, $instituteId, $student, $grade, $amount, $paidAt, $method,
            $reference !== '' ? $reference : null, $note !== '' ? $note : null,
            $role, (int)current_user_id(), (string)current_user_name()
        );
        log_activity(
            $conn, $instituteId, $role, (int)current_user_id(), (string)current_user_name(),
            'Recorded payment #' . $receiptNo . ' of ' . format_money_auto($amount) . ' for "' . student_full_name($student) . '" (grade ' . $grade . ')'
        );
        flash_set('success', 'Payment #' . $receiptNo . ' of ' . format_money_auto($amount) . ' recorded.');
        header('Location: ' . $back(['new' => $paymentId]));
        exit;
    }

    if ($action === 'void_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $payment = get_payment($conn, $paymentId, $instituteId);
        if (!$payment) {
            flash_set('danger', 'Payment not found.');
        } elseif ($reason === '') {
            flash_set('danger', 'Please give a reason for voiding the payment.');
        } elseif (mb_strlen($reason) > 255) {
            flash_set('danger', 'The reason is too long (255 characters at most).');
        } elseif (void_payment($conn, $paymentId, $instituteId, $reason, (string)current_user_name())) {
            log_activity(
                $conn, $instituteId, $role, (int)current_user_id(), (string)current_user_name(),
                'Voided payment #' . $payment['receipt_no'] . ' of ' . format_money_auto((float)$payment['amount']) . ' for "' . $payment['student_name'] . '" — ' . $reason
            );
            flash_set('success', 'Payment #' . $payment['receipt_no'] . ' was voided.');
        } else {
            flash_set('warning', 'That payment was already voided.');
        }
        header('Location: ' . $back());
        exit;
    }

    header('Location: ' . $listUrl());
    exit;
}
