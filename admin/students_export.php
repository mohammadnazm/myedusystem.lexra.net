<?php
ob_start(); // swallow accidental output from includes so it can't corrupt the .xlsx
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/xlsx_writer.php';
require_role('admin');

$instituteId = current_institute_id();

// A blank starter file: header row + one real example row built from this
// institute's own first class section, so the Department/Grade/Group
// values are guaranteed to match something that actually exists.
if (isset($_GET['template'])) {
    $sections = all_class_sections($conn, $instituteId);
    $example = $sections
        ? ['ID' => '', 'Full Name' => 'John Doe', 'National ID Number' => '1234567890',
           'Department' => $sections[0]['department_name'], 'Grade' => $sections[0]['grade'], 'Group' => $sections[0]['class_group'],
           'Gender' => 'male', 'Birth Date' => '2010-05-21', 'ID Type' => '', 'Blood Type' => 'A+', 'Nationality' => '', 'Religion' => '',
           'Clothing Size' => 'M', 'Mother Name' => '', 'Father Phone' => '07501234567', 'Mother Phone' => '', 'Brothers' => 1, 'Sisters' => 1,
           'Birth Order' => 1, 'Previous School' => '', 'Years in Current Grade' => 1, 'Last Year Average' => '85',
           'Phone' => '07507654321', 'Phone 2' => '', 'Home Address' => '', 'Added On' => '', 'Status' => '']
        : [];
    send_xlsx_download('students-import-template.xlsx', STUDENT_IMPORT_COLUMNS, $example ? [$example] : [], ['text_columns' => STUDENT_TEXT_COLUMNS]);
}

// Same optional filters as the students list page, so "export what I'm
// looking at" and "export everyone" both work from the same button set.
$filterDept = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$filterGrade = trim($_GET['grade'] ?? '');
$filterSection = isset($_GET['section']) ? (int)$_GET['section'] : 0;
$filterStatus = trim($_GET['status'] ?? '');
if (!in_array($filterStatus, ['active', 'disabled'], true)) {
    $filterStatus = '';
}
$search = trim($_GET['q'] ?? '');

$baseSql = "SELECT s.*, cs.grade, cs.class_group, d.name AS department_name
            FROM students s
            JOIN class_sections cs ON cs.id = s.class_section_id
            JOIN departments d ON d.id = cs.department_id";
$where = ['s.institute_id = ?'];
$params = [$instituteId];
$types = 'i';
if ($filterDept) {
    $where[] = 'd.id = ?';
    $params[] = $filterDept;
    $types .= 'i';
}
if ($filterGrade !== '') {
    $where[] = 'cs.grade = ?';
    $params[] = $filterGrade;
    $types .= 's';
}
if ($filterSection) {
    $where[] = 's.class_section_id = ?';
    $params[] = $filterSection;
    $types .= 'i';
}
if ($filterStatus === 'active') {
    $where[] = 's.is_active = 1';
} elseif ($filterStatus === 'disabled') {
    $where[] = 's.is_active = 0';
}
if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.second_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, ' ', COALESCE(s.second_name,''), ' ', COALESCE(s.last_name,'')) LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
$sql = $baseSql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.name, cs.grade, cs.class_group, s.first_name';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Numbers stay numbers (int) so Excel can sort/sum them; everything else is text.
$intOrBlank = fn($v) => ($v === null || $v === '') ? '' : (int)$v;
$rows = array_map(fn($s) => [
    'ID' => (int)$s['id'],
    'Full Name' => student_full_name($s),
    'National ID Number' => $s['id_number'] ?? '',
    'Department' => $s['department_name'],
    'Grade' => $s['grade'],
    'Group' => $s['class_group'],
    'Gender' => $s['gender'] ?? '',
    'Birth Date' => $s['birth_date'] ?? '',
    'ID Type' => $s['id_type'] ?? '',
    'Blood Type' => $s['blood_type'] ?? '',
    'Nationality' => $s['nationality'] ?? '',
    'Citizenship' => $s['citizenship'] ?? '',
    'Religion' => $s['religion'] ?? '',
    'Clothing Size' => $s['clothing_size'] ?? '',
    'Mother Name' => $s['mother_name'] ?? '',
    'Father Phone' => $s['father_phone'] ?? '',
    'Mother Phone' => $s['mother_phone'] ?? '',
    'Brothers' => $intOrBlank($s['brothers_count']),
    'Sisters' => $intOrBlank($s['sisters_count']),
    'Birth Order' => $intOrBlank($s['birth_order']),
    'Previous School' => $s['previous_school'] ?? '',
    'Years in Current Grade' => $intOrBlank($s['years_in_current_grade']),
    'Last Year Average' => $s['last_year_average'] ?? '',
    'Phone' => $s['phone'] ?? '',
    'Phone 2' => $s['phone2'] ?? '',
    'Home Address' => $s['home_address'] ?? '',
    'Added On' => format_added_at($s['created_at'] ?? null, true),
    'Status' => $s['is_active'] ? 'Active' : 'Disabled',
], $students);

log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), 'Exported ' . count($rows) . ' student(s) to Excel');

send_xlsx_download('students-' . date('Y-m-d') . '.xlsx', STUDENT_IMPORT_COLUMNS, $rows, ['text_columns' => STUDENT_TEXT_COLUMNS]);
