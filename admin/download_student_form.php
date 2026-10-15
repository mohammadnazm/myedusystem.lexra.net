<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();
$studentId = (int)($_GET['id'] ?? 0);

if (!$studentId || !student_belongs_to_institute($conn, $studentId, $instituteId)) {
    http_response_code(404);
    die('Not found.');
}

$stmt = $conn->prepare("SELECT form_original_filename, form_stored_filename FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student || !$student['form_stored_filename']) {
    http_response_code(404);
    die('No document attached for this student.');
}

$filePath = __DIR__ . '/../uploads/student_forms/' . $student['form_stored_filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    die('File is missing from storage.');
}

send_pdf_file($filePath, (string)$student['form_original_filename']);
