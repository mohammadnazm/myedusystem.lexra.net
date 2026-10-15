<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

const MAX_FORM_BYTES = 15 * 1024 * 1024; // 15 MB
const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5 MB

$instituteId = current_institute_id();
$formUploadDir = __DIR__ . '/../uploads/student_forms/';
$photoUploadDir = __DIR__ . '/../uploads/student_photos/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $fullName = trim($_POST['full_name'] ?? '');
        $nameParts = split_full_name($fullName);
        $gender = normalize_gender($_POST['gender'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $phone2 = trim($_POST['phone2'] ?? '');
        $homeAddress = trim($_POST['home_address'] ?? '');
        $classSectionId = (int)($_POST['class_section_id'] ?? 0);
        $idNumber = trim($_POST['id_number'] ?? '');

        // Validate every field up front, so nothing bad ever reaches the database.
        $inputErrors = [];
        $extended = student_extended_from_post($_POST, $inputErrors);
        array_push($inputErrors, ...student_core_input_errors($nameParts, $idNumber, $phone, $phone2, $homeAddress));

        // The document is optional, but if one was chosen it must be a valid PDF.
        $formError = null;
        if (!empty($_FILES['form_pdf']) && $_FILES['form_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            $formError = validate_uploaded_pdf($_FILES['form_pdf'], MAX_FORM_BYTES);
        }
        $photoFields = ['main' => 'photo_main', 'extra1' => 'photo_extra1', 'extra2' => 'photo_extra2'];
        $photoErrors = [];
        foreach ($photoFields as $slot => $field) {
            if (!empty($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
                $err = validate_uploaded_image($_FILES[$field], MAX_PHOTO_BYTES);
                if ($err) $photoErrors[] = $err;
            }
        }

        if ($nameParts['first_name'] === '' || !$classSectionId || $idNumber === '') {
            flash_set('danger', 'Name, class section, and a National ID Number are required.');
        } elseif (!class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) {
            flash_set('danger', 'Class section not found.');
        } elseif ($formError) {
            flash_set('danger', $formError);
        } elseif ($photoErrors) {
            flash_set('danger', implode(' ', $photoErrors));
        } elseif ($inputErrors) {
            flash_set('danger', implode(' ', $inputErrors));
        } elseif (student_national_id_taken($conn, $instituteId, $idNumber)) {
            flash_set('danger', 'That National ID Number is already used by another student in this institute.');
        } else {
            // The ID number doubles as the login password — hashed like any
            // other password, but there's no separate password for the
            // admin to make up or remember.
            $hash = password_hash($idNumber, PASSWORD_DEFAULT);
            $createdFiles = [];
            try {
                // Row + profile fields + photo/PDF references are saved together or not at all,
                // so a failure can never leave a half-created student behind.
                $conn->begin_transaction();
                $stmt = $conn->prepare("INSERT INTO students (institute_id, first_name, second_name, last_name, id_number, gender, phone, phone2, home_address, password_hash, class_section_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isssssssssi', $instituteId, $nameParts['first_name'], $nameParts['second_name'], $nameParts['last_name'], $idNumber, $gender, $phone, $phone2, $homeAddress, $hash, $classSectionId);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();

                save_student_extended_profile($conn, $newId, $extended);

                foreach ($photoFields as $slot => $field) {
                    if (!empty($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                        $stored = store_uploaded_image($_FILES[$field], $photoUploadDir);
                        if ($stored) {
                            $createdFiles[] = $photoUploadDir . $stored;
                            save_student_photo($conn, $newId, $slot, $_FILES[$field]['name'], $stored);
                        }
                    }
                }

                if (!empty($_FILES['form_pdf']) && $_FILES['form_pdf']['error'] === UPLOAD_ERR_OK) {
                    $stored = store_uploaded_pdf($_FILES['form_pdf'], $formUploadDir);
                    if ($stored) {
                        $createdFiles[] = $formUploadDir . $stored;
                        save_student_form($conn, $newId, $_FILES['form_pdf']['name'], $stored, (int)$_FILES['form_pdf']['size']);
                    }
                }
                $conn->commit();

                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added student \"$fullName\" (ID $newId)");
                flash_set('success', "Student added. Login ID: $newId — National ID Number (used as their password): $idNumber.");
                header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $newId);
                exit;
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                foreach ($createdFiles as $orphan) {
                    @unlink($orphan);
                }
                if ((int)$e->getCode() === 1062) {
                    flash_set('danger', 'That National ID Number is already used by another student in this institute.');
                } else {
                    error_log('Add student failed: ' . $e->getMessage());
                    flash_set('danger', 'The student could not be saved because of a database error — nothing was added. Please check the details and try again.');
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_student($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("UPDATE students SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $studentName = student_full_name($before);
            $oldStatus = $before['is_active'] ? 'Active' : 'Disabled';
            $newStatus = $before['is_active'] ? 'Disabled' : 'Active';
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Changed status for student \"$studentName\" (ID $id): $oldStatus → $newStatus");
            flash_set('success', 'Student status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_student($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            delete_student_files($before); // their PDF form and photos go too
            $studentName = student_full_name($before);
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted student \"$studentName\" (ID $id, was in " . class_section_label($before) . ")");
            flash_set('success', 'Student deleted.');
        }
    } elseif ($action === 'delete_all') {
        $expectedPhrase = 'DELETE ALL STUDENTS';
        $confirmPhrase = trim($_POST['confirm_phrase'] ?? '');
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $adminId = current_user_id();
        $adminStmt = $conn->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $adminStmt->bind_param('i', $adminId);
        $adminStmt->execute();
        $adminRow = $adminStmt->get_result()->fetch_assoc();

        if ($confirmPhrase !== $expectedPhrase) {
            flash_set('danger', 'Confirmation text didn\'t match exactly — nothing was deleted.');
        } elseif (!$adminRow || !password_verify($confirmPassword, $adminRow['password_hash'])) {
            flash_set('danger', 'That password was incorrect — nothing was deleted.');
        } else {
            $allStmt = $conn->prepare("SELECT id, form_stored_filename, photo_stored_filename, extra_photo1_stored_filename, extra_photo2_stored_filename FROM students WHERE institute_id = ?");
            $allStmt->bind_param('i', $instituteId);
            $allStmt->execute();
            $allStudents = $allStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($allStudents as $s) {
                delete_student_files($s);
            }

            $delStmt = $conn->prepare("DELETE FROM students WHERE institute_id = ?");
            $delStmt->bind_param('i', $instituteId);
            $delStmt->execute();
            $deletedCount = count($allStudents);

            if ($deletedCount > 0) {
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted ALL students ($deletedCount total) after typed + password confirmation");
            }
            flash_set('success', "$deletedCount student(s) deleted.");
        }
    }
    header('Location: ' . base_url('admin/students.php'));
    exit;
}

$myInstitute = get_institute($conn, $instituteId);
$sections = all_class_sections($conn, $instituteId);
$departments = all_departments($conn, $instituteId);
$gradesByDeptJson = [];
foreach ($departments as $d) {
    $gradesByDeptJson[(string)$d['id']] = grades_for_department($conn, (int)$d['id'], $instituteId);
}

$filterDept = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$filterGrade = trim($_GET['grade'] ?? '');
$filterSection = isset($_GET['section']) ? (int)$_GET['section'] : 0;
$filterStatus = trim($_GET['status'] ?? '');
if (!in_array($filterStatus, ['active', 'disabled'], true)) {
    $filterStatus = '';
}
$search = trim($_GET['q'] ?? '');
$gradesForFilter = $filterDept ? ($gradesByDeptJson[(string)$filterDept] ?? []) : [];
$sectionsForFilter = array_values(array_filter($sections, function ($s) use ($filterDept, $filterGrade) {
    if ($filterDept && (int)$s['department_id'] !== $filterDept) return false;
    if ($filterGrade !== '' && $s['grade'] !== $filterGrade) return false;
    return true;
}));

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
$sql = $baseSql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.first_name';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Students';
$role = 'admin';
$activePage = 'students';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$idTypes = STUDENT_ID_TYPES;
$clothingSizes = STUDENT_CLOTHING_SIZES;
$religions = STUDENT_RELIGIONS;
$nationalities = STUDENT_NATIONALITIES;
$citizenships = STUDENT_CITIZENSHIPS;
?>
<div class="panel mb-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2" data-bs-toggle="collapse" data-bs-target="#addStudentForm" role="button" style="cursor:pointer;">
    <div class="panel-title mb-0"><i class="bi bi-person-plus me-1"></i>Add student</div>
    <div class="d-flex align-items-center gap-2">
      <?php if ($myInstitute && $myInstitute['profile_template_stored_filename']): ?>
        <a href="<?= base_url('admin/download_profile_template.php') ?>" target="_blank" class="small text-decoration-none" onclick="event.stopPropagation();"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Blank profile form</a>
      <?php endif; ?>
      <i class="bi bi-chevron-down"></i>
    </div>
  </div>
  <?php if (!$sections): ?>
    <div class="alert alert-warning py-2 mt-2">Add a class section first.</div>
  <?php else: ?>
    <div class="collapse <?= isset($_GET['add']) ? 'show' : '' ?>" id="addStudentForm">
      <form method="post" enctype="multipart/form-data" class="mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="form-scroll-area">
        <div class="row g-4">
          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-person-lines-fill"></i>Personal &amp; identification</div>
              <div class="row g-2">
                <div class="col-sm-12">
                  <label class="form-label small mb-1">Full name</label>
                  <input type="text" name="full_name" class="form-control form-control-sm kurdish-text" placeholder="e.g. Ahmed Karim Rasul" required>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Gender</label>
                  <select name="gender" class="form-select form-select-sm">
                    <option value="">Not set</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">National ID Number <span class="text-muted kurdish-text">کۆدی ناسنامە</span></label>
                  <input type="text" name="id_number" class="form-control form-control-sm" required>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">ID type <span class="text-muted kurdish-text">جۆری ناسنامە</span></label>
                  <select name="id_type" class="form-select form-select-sm kurdish-text">
                    <option value="">Not set</option>
                    <?php foreach ($idTypes as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Birth date <span class="text-muted kurdish-text">بەرواری لەدایکبوون</span></label>
                  <input type="date" name="birth_date" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Blood type <span class="text-muted kurdish-text">گروپی خوێن</span></label>
                  <select name="blood_type" class="form-select form-select-sm">
                    <option value="">Not set</option>
                    <?php foreach ($bloodTypes as $bt): ?>
                      <option value="<?= h($bt) ?>"><?= h($bt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Nationality <span class="text-muted kurdish-text">نەتەوە</span></label>
                  <select name="nationality" class="form-select form-select-sm kurdish-text">
                    <option value="">Not set</option>
                    <?php foreach ($nationalities as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Citizenship <span class="text-muted kurdish-text">هاوڵاتیبوون</span></label>
                  <select name="citizenship" class="form-select form-select-sm kurdish-text">
                    <option value="">Not set</option>
                    <?php foreach ($citizenships as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Religion <span class="text-muted kurdish-text">ئاین</span></label>
                  <select name="religion" class="form-select form-select-sm kurdish-text">
                    <option value="">Not set</option>
                    <?php foreach ($religions as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Clothing size <span class="text-muted kurdish-text">سایزی خل و بەرگ</span></label>
                  <select name="clothing_size" class="form-select form-select-sm">
                    <option value="">Not set</option>
                    <?php foreach ($clothingSizes as $opt): ?>
                      <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-people"></i>Family</div>
              <div class="row g-2">
                <div class="col-sm-12">
                  <label class="form-label small mb-1">Mother's name <span class="text-muted kurdish-text">ناوی سیانی دایك</span></label>
                  <input type="text" name="mother_name" class="form-control form-control-sm kurdish-text">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Father's phone <span class="text-muted kurdish-text">ژمارە مۆبایل باوك</span></label>
                  <input type="text" name="father_phone" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Mother's phone <span class="text-muted kurdish-text">ژمارە مۆبایل دایك</span></label>
                  <input type="text" name="mother_phone" class="form-control form-control-sm">
                </div>
                <div class="col-sm-4">
                  <label class="form-label small mb-1">Brothers <span class="text-muted kurdish-text">ژمارەی برا</span></label>
                  <input type="number" min="0" name="brothers_count" class="form-control form-control-sm">
                </div>
                <div class="col-sm-4">
                  <label class="form-label small mb-1">Sisters <span class="text-muted kurdish-text">ژمارەی خوشك</span></label>
                  <input type="number" min="0" name="sisters_count" class="form-control form-control-sm">
                </div>
                <div class="col-sm-4">
                  <label class="form-label small mb-1">Birth order <span class="text-muted kurdish-text">چەندەمین منداڵە</span></label>
                  <input type="number" min="1" name="birth_order" class="form-control form-control-sm">
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-mortarboard"></i>Academic</div>
              <div class="row g-2">
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Class section</label>
                  <select name="class_section_id" class="form-select form-select-sm" required>
                    <option value="">Select...</option>
                    <?php foreach ($sections as $s): ?>
                      <option value="<?= (int)$s['id'] ?>"><?= h(class_section_label($s)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Previous school <span class="text-muted kurdish-text">کۆتا قوتابخانە</span></label>
                  <input type="text" name="previous_school" class="form-control form-control-sm kurdish-text">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Years in current grade <span class="text-muted kurdish-text">چەندەمین ساڵە لەم پۆلە</span></label>
                  <input type="number" min="1" name="years_in_current_grade" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Last year's average <span class="text-muted kurdish-text">کۆمەڵی نمرەی دوایین ساڵ</span></label>
                  <input type="text" name="last_year_average" class="form-control form-control-sm">
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-telephone"></i>Contact &amp; address</div>
              <div class="row g-2">
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Student phone <span class="text-muted kurdish-text">ژمارە مۆبایل قوتابی</span></label>
                  <input type="text" name="phone" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Student phone 2</label>
                  <input type="text" name="phone2" class="form-control form-control-sm">
                </div>
                <div class="col-sm-12">
                  <label class="form-label small mb-1">Home address <span class="text-muted kurdish-text">ناونیشانی مال</span></label>
                  <input type="text" name="home_address" class="form-control form-control-sm kurdish-text" placeholder="e.g. neighborhood / area, city">
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-camera"></i>Photos</div>
              <div class="row g-2">
                <div class="col-sm-12">
                  <label class="form-label small mb-1">Profile photo <span class="text-muted kurdish-text">وێنەی قوتابی</span></label>
                  <input type="file" name="photo_main" accept="image/png,image/jpeg,image/gif,image/webp" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Extra photo 1 <span class="text-muted kurdish-text">وێنە ١</span></label>
                  <input type="file" name="photo_extra1" accept="image/png,image/jpeg,image/gif,image/webp" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6">
                  <label class="form-label small mb-1">Extra photo 2 <span class="text-muted kurdish-text">وێنە ٢</span></label>
                  <input type="file" name="photo_extra2" accept="image/png,image/jpeg,image/gif,image/webp" class="form-control form-control-sm">
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="profile-section">
              <div class="profile-section-title"><i class="bi bi-file-earmark-pdf"></i>Attached document</div>
              <label class="form-label small mb-1">Registration / application form (optional PDF)</label>
              <input type="file" name="form_pdf" accept="application/pdf,.pdf" class="form-control form-control-sm">
              <div class="form-text">Max 15 MB.</div>
              <div class="form-text mt-2">Students log in with their auto-generated login ID + the National ID Number above as their password.</div>
            </div>
          </div>
        </div>
        </div>

        <div class="form-scroll-footer">
          <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg me-1"></i>Add student</button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="row g-4 content-fill">
  <div class="col-lg-12">

    <div class="panel">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="panel-title mb-0">All students (<?= count($students) ?>)</div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="<?= base_url('admin/students_export.php') ?>?<?= http_build_query(['department_id' => $filterDept ?: null, 'grade' => $filterGrade ?: null, 'section' => $filterSection ?: null, 'status' => $filterStatus ?: null, 'q' => $search ?: null]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Export to Excel</a>
          <a href="<?= base_url('admin/students_import.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload me-1"></i>Import from Excel</a>
          <?php if ($students): ?>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal"><i class="bi bi-exclamation-triangle me-1"></i>Delete all students</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex justify-content-end mb-3">
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input type="text" name="q" class="form-control form-control-sm" style="min-width:180px" placeholder="Search by name..." value="<?= h($search) ?>">
          <select name="department_id" class="form-select form-select-sm" onchange="if(this.form.grade)this.form.grade.value='';if(this.form.section)this.form.section.value='';this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($filterDept): ?>
            <select name="grade" class="form-select form-select-sm" onchange="if(this.form.section)this.form.section.value='';this.form.submit()">
              <option value="">All grades</option>
              <?php foreach ($gradesForFilter as $g): ?>
                <option value="<?= h($g) ?>" <?= $filterGrade === $g ? 'selected' : '' ?>>Grade <?= h($g) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <select name="section" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All groups</option>
            <?php foreach ($sectionsForFilter as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $filterSection === (int)$s['id'] ? 'selected' : '' ?>><?= h(class_section_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Active &amp; disabled</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="disabled" <?= $filterStatus === 'disabled' ? 'selected' : '' ?>>Disabled only</option>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
          <?php if ($search !== '' || $filterDept || $filterGrade !== '' || $filterSection || $filterStatus !== ''): ?>
            <a href="<?= base_url('admin/students.php') ?>" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (!$students): ?>
        <div class="empty-state"><i class="bi bi-people"></i><?= ($search !== '' || $filterDept || $filterGrade !== '' || $filterSection || $filterStatus !== '') ? 'No students match your search.' : 'No students found.' ?></div>
      <?php else: ?>
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>ID</th><th>National ID</th><th>Name</th><th>Gender</th><th>Class</th><th>Phone</th><th>Phone 2</th><th>Home Address</th><th>Document</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($students as $s): ?>
              <tr>
                <td class="text-muted">#<?= (int)$s['id'] ?></td>
                <td class="small"><?= h($s['id_number']) ?: '<span class="text-muted">—</span>' ?></td>
                <td class="fw-semibold"><a href="<?= base_url('admin/student_profile.php') ?>?id=<?= (int)$s['id'] ?>" class="text-decoration-none"><?= h(student_full_name($s)) ?></a></td>
                <td class="text-muted small"><?= gender_label($s['gender']) ?></td>
                <td class="small"><?= h($s['department_name']) ?> · G<?= h($s['grade']) ?> · <?= h($s['class_group']) ?></td>
                <td class="text-muted small"><?= h($s['phone']) ?: '—' ?></td>
                <td class="text-muted small"><?= h($s['phone2']) ?: '—' ?></td>
                <td class="text-muted small"><?= h($s['home_address']) ?: '—' ?></td>
                <td class="small">
                  <?php if ($s['form_stored_filename']): ?>
                    <a href="<?= base_url('admin/download_student_form.php') ?>?id=<?= (int)$s['id'] ?>" target="_blank" class="text-decoration-none">
                      <i class="bi bi-file-earmark-pdf text-danger"></i> PDF
                    </a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($s['is_active']): ?>
                    <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Disabled</span>
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <a href="<?= base_url('admin/student_profile.php') ?>?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View full profile"><i class="bi bi-person-vcard"></i></a>
                  <?php if ($s['form_stored_filename']): ?>
                    <a href="<?= base_url('admin/download_student_form.php') ?>?id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View attached document"><i class="bi bi-file-earmark-pdf"></i></a>
                  <?php endif; ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-toggle2-on"></i></button>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete this student permanently?');" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($students): ?>
<div class="modal fade" id="deleteAllModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" id="deleteAllForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_all">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete ALL students</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">
          This permanently deletes <strong>all <?= count($students) ?> student(s)</strong> in your institute —
          their marks, attached documents, and login access. <strong>This cannot be undone.</strong>
          Consider exporting to Excel first as a backup.
        </p>
        <div class="mb-3">
          <label class="form-label small">Type <code>DELETE ALL STUDENTS</code> to confirm</label>
          <input type="text" name="confirm_phrase" id="deleteAllPhrase" class="form-control" autocomplete="off" required>
        </div>
        <div class="mb-1">
          <label class="form-label small">Enter your admin password</label>
          <input type="password" name="confirm_password" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger" id="deleteAllSubmit" disabled>Permanently delete all students</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var phrase = document.getElementById('deleteAllPhrase');
  var submitBtn = document.getElementById('deleteAllSubmit');
  if (phrase && submitBtn) {
    phrase.addEventListener('input', function () {
      submitBtn.disabled = phrase.value !== 'DELETE ALL STUDENTS';
    });
  }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
