<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('owner');

$id = (int)($_GET['id'] ?? 0);
$institute = $id ? get_institute($conn, $id) : null;

if (!$institute || !$institute['profile_template_stored_filename']) {
    http_response_code(404);
    die('No profile form template has been attached to this institute yet.');
}

$filePath = __DIR__ . '/../uploads/profile_templates/' . $institute['profile_template_stored_filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    die('File is missing from storage.');
}

send_pdf_file($filePath, (string)$institute['profile_template_original_filename']);
