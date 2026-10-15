<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$resource = $id ? get_resource($conn, $id) : null;

if (!$resource) {
    http_response_code(404);
    die('File not found.');
}

$role = current_role();
$allowed = false;

if ($role === 'admin') {
    $allowed = department_belongs_to_institute($conn, (int)$resource['department_id'], current_institute_id());
} elseif ($role === 'teacher') {
    $allowed = ((int)$resource['teacher_id'] === current_user_id())
        || teacher_can_access_grade_subject($conn, current_user_id(), (int)$resource['department_id'], $resource['grade'], (int)$resource['subject_id']);
} elseif ($role === 'student') {
    $myGrade = student_department_and_grade($conn, current_user_id());
    $allowed = $myGrade !== null
        && $myGrade['department_id'] === (int)$resource['department_id']
        && $myGrade['grade'] === $resource['grade'];
}

if (!$allowed) {
    http_response_code(403);
    die('You do not have access to this file.');
}

$filePath = __DIR__ . '/uploads/resources/' . $resource['stored_filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    die('File is missing from storage.');
}

send_pdf_file($filePath, (string)$resource['original_filename']);
