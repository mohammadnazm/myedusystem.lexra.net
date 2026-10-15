<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/xlsx_writer.php';
require_role('admin');

$instituteId = current_institute_id();

// Same optional search as the teachers list page, so "export what I'm
// looking at" and "export everyone" both work from the same button.
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE institute_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY full_name");
    $stmt->bind_param('isss', $instituteId, $like, $like, $like);
} else {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE institute_id = ? ORDER BY full_name");
    $stmt->bind_param('i', $instituteId);
}
$stmt->execute();
$teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$rows = array_map(fn($t) => [
    'ID' => $t['id'],
    'Full Name' => $t['full_name'],
    'Gender' => gender_label($t['gender']),
    'Username' => $t['username'],
    'Email' => $t['email'],
    'Phone' => $t['phone'],
    'Joined' => $t['created_at'],
    'Status' => $t['is_active'] ? 'Active' : 'Disabled',
], $teachers);

log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), 'Exported ' . count($rows) . ' teacher(s) to Excel');

send_xlsx_download('teachers-' . date('Y-m-d') . '.xlsx', TEACHER_EXPORT_COLUMNS, $rows);
