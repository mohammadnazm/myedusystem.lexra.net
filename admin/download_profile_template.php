<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();
$institute = get_institute($conn, $instituteId);

if (!$institute || !$institute['profile_template_stored_filename']) {
    http_response_code(404);
    die('No profile form template has been attached to your institute yet.');
}

$filePath = __DIR__ . '/../uploads/profile_templates/' . $institute['profile_template_stored_filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    die('File is missing from storage.');
}

send_pdf_file($filePath, (string)$institute['profile_template_original_filename']);
