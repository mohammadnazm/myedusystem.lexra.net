<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/xlsx_writer.php';
require_role('admin');

$instituteId = current_institute_id();

// Same optional filters as the Accounting page's Discounts tab, so
// "export what I'm looking at" and "export every discounted student"
// both work from the same button.
$filterDept = (int)($_GET['department_id'] ?? 0);
$filterGrade = trim($_GET['grade'] ?? '');
$search = trim($_GET['q'] ?? '');

$rows = students_with_pricing($conn, $instituteId, $filterDept ?: null, $filterGrade ?: null, $search, true);

$columns = ['ID', 'Full Name', 'Department', 'Grade', 'Group', 'Base Price', 'Discount Type', 'Discount Value', 'Reason', 'Final Price'];
$data = array_map(fn($s) => [
    'ID' => $s['id'],
    'Full Name' => student_full_name($s),
    'Department' => $s['department_name'],
    'Grade' => $s['grade'],
    'Group' => $s['class_group'],
    'Base Price' => $s['base_price'],
    'Discount Type' => $s['discount_type'] === 'percent' ? 'Percent' : 'Fixed',
    'Discount Value' => $s['discount_value'],
    'Reason' => $s['discount_reason'] ?? '',
    'Final Price' => $s['final_price'],
], $rows);

log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), 'Exported ' . count($data) . ' discounted student(s) to Excel');

send_xlsx_download('student-discounts-' . date('Y-m-d') . '.xlsx', $columns, $data);
