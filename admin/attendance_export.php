<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/xlsx_writer.php';
require_role('admin');

$instituteId = current_institute_id();

$departmentId = isset($_GET['department']) && $_GET['department'] !== '' ? (int)$_GET['department'] : null;
$classSectionId = isset($_GET['section']) && $_GET['section'] !== '' ? (int)$_GET['section'] : null;
$subjectId = isset($_GET['subject']) && $_GET['subject'] !== '' ? (int)$_GET['subject'] : null;
$status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
if ($departmentId && !department_belongs_to_institute($conn, $departmentId, $instituteId)) { $departmentId = null; }
if ($classSectionId && !class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) { $classSectionId = null; }
if ($subjectId && !subject_belongs_to_institute($conn, $subjectId, $instituteId)) { $subjectId = null; }
if ($status && !in_array($status, ATTENDANCE_STATUSES, true)) { $status = null; }

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
$to = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-14 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

$records = attendance_records_range($conn, $instituteId, $from, $to, $departmentId, $classSectionId, $subjectId, $status);

$columns = ['Date', 'Student', 'Grade', 'Class', 'Department', 'Subject', 'Lesson', 'Status', 'Remarks'];
$rows = array_map(function ($r) {
    $fullName = trim(implode(' ', array_filter([$r['first_name'], $r['second_name'], $r['last_name']])));
    return [
        'Date' => date('Y-m-d', strtotime($r['attendance_date'])),
        'Student' => $fullName,
        'Grade' => $r['grade'],
        'Class' => 'Group ' . $r['class_group'],
        'Department' => $r['department_name'],
        'Subject' => $r['subject_name'],
        'Lesson' => $r['lesson'],
        'Status' => ucfirst($r['status']),
        'Remarks' => $r['remarks'] ?? '',
    ];
}, $records);

log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), 'Exported ' . count($rows) . " attendance record(s) to Excel ($from to $to)");

send_xlsx_download('attendance_' . $from . '_to_' . $to . '.xlsx', $columns, $rows);
