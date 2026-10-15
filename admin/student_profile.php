<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5 MB
const MAX_PROFILE_FORM_BYTES = 15 * 1024 * 1024; // 15 MB

$instituteId = current_institute_id();
$photoUploadDir = __DIR__ . '/../uploads/student_photos/';
$formUploadDir = __DIR__ . '/../uploads/student_forms/';

$studentId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$studentId || !student_belongs_to_institute($conn, $studentId, $instituteId)) {
    flash_set('danger', 'Student not found.');
    header('Location: ' . base_url('admin/students.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $before = get_student($conn, $studentId, $instituteId);

        $fullName = trim($_POST['full_name'] ?? '');
        $nameParts = split_full_name($fullName);
        $gender = normalize_gender($_POST['gender'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $phone2 = trim($_POST['phone2'] ?? '');
        $homeAddress = trim($_POST['home_address'] ?? '');
        $classSectionId = (int)($_POST['class_section_id'] ?? 0);
        $idNumber = trim($_POST['id_number'] ?? '');

        if ($nameParts['first_name'] === '' || !$classSectionId || $idNumber === '') {
            flash_set('danger', 'Name, class section, and a National ID Number are required.');
            header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
            exit;
        }
        if (!class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) {
            flash_set('danger', 'Class section not found.');
            header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
            exit;
        }

        // Validate everything first — a bad value is reported, never silently dropped or half-saved.
        $inputErrors = [];
        $data = student_extended_from_post($_POST, $inputErrors);
        array_push($inputErrors, ...student_core_input_errors($nameParts, $idNumber, $phone, $phone2, $homeAddress));
        if ($inputErrors) {
            flash_set('danger', implode(' ', $inputErrors));
            header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
            exit;
        }

        // If the ID number changed, their login password changes with it —
        // the two are always kept in sync, so there's nothing separate for
        // the admin to reset.
        $idNumberChanged = $idNumber !== ($before['id_number'] ?? '');
        if ($idNumberChanged) {
            $hash = password_hash($idNumber, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE students SET first_name = ?, second_name = ?, last_name = ?, id_number = ?, password_hash = ?, gender = ?, phone = ?, phone2 = ?, home_address = ?, class_section_id = ? WHERE id = ?");
            $stmt->bind_param('sssssssssii', $nameParts['first_name'], $nameParts['second_name'], $nameParts['last_name'], $idNumber, $hash, $gender, $phone, $phone2, $homeAddress, $classSectionId, $studentId);
        } else {
            $stmt = $conn->prepare("UPDATE students SET first_name = ?, second_name = ?, last_name = ?, id_number = ?, gender = ?, phone = ?, phone2 = ?, home_address = ?, class_section_id = ? WHERE id = ?");
            $stmt->bind_param('ssssssssii', $nameParts['first_name'], $nameParts['second_name'], $nameParts['last_name'], $idNumber, $gender, $phone, $phone2, $homeAddress, $classSectionId, $studentId);
        }

        try {
            // The main details and the extra profile fields are saved together, or not at all.
            $conn->begin_transaction();
            $stmt->execute();
            save_student_extended_profile($conn, $studentId, $data);
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ((int)$e->getCode() === 1062) {
                flash_set('danger', 'That National ID Number is already used by another student in this institute.');
            } else {
                error_log('Edit student failed: ' . $e->getMessage());
                flash_set('danger', 'The changes could not be saved because of a database error — nothing was changed.');
            }
            header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
            exit;
        }

        $photoErrors = [];
        $photoFields = ['main' => 'photo_main', 'extra1' => 'photo_extra1', 'extra2' => 'photo_extra2'];
        foreach ($photoFields as $slot => $field) {
            if (!empty($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
                $imgError = validate_uploaded_image($_FILES[$field], MAX_PHOTO_BYTES);
                if ($imgError) {
                    $photoErrors[] = $imgError;
                } else {
                    $old = clear_student_photo($conn, $studentId, $slot);
                    if ($old) {
                        @unlink($photoUploadDir . $old);
                    }
                    $stored = store_uploaded_image($_FILES[$field], $photoUploadDir);
                    if ($stored) {
                        save_student_photo($conn, $studentId, $slot, $_FILES[$field]['name'], $stored);
                    }
                }
            }
        }

        $newSection = class_section_belongs_to_institute($conn, $classSectionId, $instituteId)
            ? $conn->query("SELECT cs.grade, cs.class_group, d.name AS department_name FROM class_sections cs JOIN departments d ON d.id = cs.department_id WHERE cs.id = " . (int)$classSectionId)->fetch_assoc()
            : null;
        $coreAfter = [
            'first_name' => $nameParts['first_name'], 'second_name' => $nameParts['second_name'], 'last_name' => $nameParts['last_name'],
            'id_number' => $idNumber, 'gender' => $gender, 'phone' => $phone, 'phone2' => $phone2, 'home_address' => $homeAddress,
            'class_section_id' => $newSection ? class_section_label($newSection) : $classSectionId,
        ];
        $coreBefore = $before;
        $coreBefore['class_section_id'] = class_section_label($before);
        $coreLabels = ['first_name' => 'First name', 'second_name' => 'Second name', 'last_name' => 'Last name', 'id_number' => 'National ID Number', 'gender' => 'Gender', 'phone' => 'Phone', 'phone2' => 'Phone 2', 'home_address' => 'Home address', 'class_section_id' => 'Class section'];
        $extendedLabels = [
            'mother_name' => "Mother's name", 'birth_date' => 'Birth date', 'blood_type' => 'Blood type',
            'nationality' => 'Nationality', 'citizenship' => 'Citizenship', 'religion' => 'Religion', 'clothing_size' => 'Clothing size',
            'brothers_count' => 'Brothers', 'sisters_count' => 'Sisters', 'birth_order' => 'Birth order',
            'father_phone' => "Father's phone", 'mother_phone' => "Mother's phone", 'previous_school' => 'Previous school',
            'years_in_current_grade' => 'Years in current grade', 'id_type' => 'ID type',
            'last_year_average' => "Last year's average",
        ];
        $changes = trim(diff_summary($coreBefore, $coreAfter, $coreLabels) . '; ' . diff_summary($before, $data, $extendedLabels), '; ');
        $studentName = student_full_name($before);
        $summary = $changes !== '' ? "Updated student \"$studentName\" (ID $studentId) — $changes" : "Updated student \"$studentName\" (ID $studentId) (no changes)";
        if (mb_strlen($summary) > 255) {
            $summary = mb_substr($summary, 0, 252) . '...';
        }
        log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), $summary);

        if ($photoErrors) {
            flash_set('danger', 'Profile saved, but a photo was skipped: ' . implode(' ', $photoErrors));
        } else {
            flash_set('success', 'Profile updated.');
        }
        header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId);
        exit;
    } elseif ($action === 'remove_photo') {
        $slot = $_POST['slot'] ?? '';
        $old = clear_student_photo($conn, $studentId, $slot);
        if ($old) {
            @unlink($photoUploadDir . $old);
        }
        flash_set('success', 'Photo removed.');
        header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
        exit;
    } elseif ($action === 'update_document') {
        $formError = null;
        if (!empty($_FILES['form_pdf']) && $_FILES['form_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            $formError = validate_uploaded_pdf($_FILES['form_pdf'], MAX_PROFILE_FORM_BYTES);
        }
        if ($formError) {
            flash_set('danger', $formError);
        } elseif (!empty($_FILES['form_pdf']) && $_FILES['form_pdf']['error'] === UPLOAD_ERR_OK) {
            $oldStored = clear_student_form($conn, $studentId);
            if ($oldStored) {
                @unlink($formUploadDir . $oldStored);
            }
            $stored = store_uploaded_pdf($_FILES['form_pdf'], $formUploadDir);
            if ($stored) {
                save_student_form($conn, $studentId, $_FILES['form_pdf']['name'], $stored, (int)$_FILES['form_pdf']['size']);
            }
            flash_set('success', 'Attached document updated.');
        }
        header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
        exit;
    } elseif ($action === 'remove_document') {
        $before = get_student($conn, $studentId, $instituteId);
        $oldStored = clear_student_form($conn, $studentId);
        if ($oldStored) {
            @unlink($formUploadDir . $oldStored);
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Removed attached document for student \"" . student_full_name($before) . "\" (ID $studentId)");
        }
        flash_set('success', 'Attached document removed.');
        header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId . '&edit=1');
        exit;
    }
    header('Location: ' . base_url('admin/student_profile.php') . '?id=' . $studentId);
    exit;
}

$student = get_student($conn, $studentId, $instituteId);
if (!$student) {
    flash_set('danger', 'Student not found.');
    header('Location: ' . base_url('admin/students.php'));
    exit;
}
$institute = get_institute($conn, $instituteId);
$editMode = isset($_GET['edit']);
$sections = all_class_sections($conn, $instituteId);

$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$idTypes = STUDENT_ID_TYPES;
$clothingSizes = STUDENT_CLOTHING_SIZES;
$religions = STUDENT_RELIGIONS;
$nationalities = STUDENT_NATIONALITIES;
$citizenships = STUDENT_CITIZENSHIPS;

/** Renders one read-only field: an English label, an optional small Kurdish
 *  label next to it, and the value (or a muted placeholder if empty). */
function profile_field(string $label, ?string $kurdish, $value, bool $isKurdishValue = false): void
{
    $display = ($value === null || $value === '') ? '—' : (string)$value;
    $empty = ($value === null || $value === '');
    ?>
    <div class="profile-field">
      <label><?= h($label) ?><?php if ($kurdish): ?><span class="kurdish-label kurdish-text"><?= h($kurdish) ?></span><?php endif; ?></label>
      <div class="value <?= $empty ? 'empty' : '' ?> <?= $isKurdishValue ? 'kurdish-text' : '' ?>"><?= h($display) ?></div>
    </div>
    <?php
}

// Photos are embedded straight into the page (see student_photo_data_uri()).
$photoData = [
    'main'   => student_photo_data_uri($student['photo_stored_filename']),
    'extra1' => student_photo_data_uri($student['extra_photo1_stored_filename']),
    'extra2' => student_photo_data_uri($student['extra_photo2_stored_filename']),
];
$photoLabels = ['main' => 'Profile photo', 'extra1' => 'Extra photo 1', 'extra2' => 'Extra photo 2'];

// Printed header: institute logo (top-left) + institute name (top-centre).
$printLogo = institute_logo_data_uri($institute);
$printInstituteName = trim((string)($institute['name'] ?? '')) !== '' ? $institute['name'] : (current_institute_name() ?? '');

$pageTitle = 'Student Profile';
$role = 'admin';
$activePage = 'students';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
  <a href="<?= base_url('admin/students.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to students</a>
  <div class="d-flex gap-2">
    <?php if ($institute && $institute['profile_template_stored_filename']): ?>
      <a href="<?= base_url('admin/download_profile_template.php') ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Blank form template</a>
    <?php endif; ?>
    <a href="<?= base_url('admin/student_payments.php') ?>?id=<?= $studentId ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-receipt me-1"></i>Fees &amp; payments</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    <?php if ($editMode): ?>
      <a href="<?= base_url('admin/student_profile.php') ?>?id=<?= $studentId ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View</a>
    <?php else: ?>
      <a href="<?= base_url('admin/student_profile.php') ?>?id=<?= $studentId ?>&edit=1" class="btn btn-sm btn-brand"><i class="bi bi-pencil me-1"></i>Edit profile</a>
    <?php endif; ?>
  </div>
</div>

<?php /* Only visible on paper (see @media print in style.css). */ ?>
<div class="print-header">
  <div class="print-header-logo">
    <?php if ($printLogo): ?><img src="<?= $printLogo ?>" alt=""><?php endif; ?>
  </div>
  <div class="print-header-title">
    <div class="print-header-name kurdish-text" dir="auto"><?= h($printInstituteName) ?></div>
    <div class="print-header-sub">Student Profile</div>
  </div>
  <div></div>
</div>

<div class="profile-hero mb-4">
  <div class="profile-photo-frame">
    <?php if ($photoData['main']['src']): ?>
      <img src="<?= $photoData['main']['src'] ?>" alt="">
    <?php else: ?>
      <i class="bi bi-person"></i>
    <?php endif; ?>
  </div>
  <div>
    <div class="profile-hero-name kurdish-text"><?= h(student_full_name($student)) ?></div>
    <div class="profile-hero-meta">
      <span class="badge">#<?= (int)$student['id'] ?></span>
      <span class="badge"><?= h($student['department_name']) ?> · G<?= h($student['grade']) ?> · <?= h($student['class_group']) ?></span>
      <span class="badge"><?= gender_label($student['gender']) ?></span>
      <?php if ($student['is_active']): ?>
        <span class="badge">Active</span>
      <?php else: ?>
        <span class="badge">Disabled</span>
      <?php endif; ?>
    </div>
    <?php $addedOn = format_added_at($student['created_at'] ?? null); ?>
    <?php if ($addedOn !== ''): ?>
      <div class="profile-hero-added"><i class="bi bi-calendar-plus me-1"></i>Added on <?= h($addedOn) ?></div>
    <?php endif; ?>

  </div>
</div>

<?php foreach ($photoData as $slotKey => $pd): if ($pd['error']): ?>
  <div class="alert alert-warning py-2 small no-print mb-2">
    <i class="bi bi-exclamation-triangle me-1"></i><strong><?= h($photoLabels[$slotKey]) ?></strong> is saved for this student but can't be shown: <?= h($pd['error']) ?>.
  </div>
<?php endif; endforeach; ?>

<?php if (!$editMode): ?>

  <div class="row g-4 profile-view-row">
    <div class="col-lg-6">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-person-lines-fill"></i>Personal &amp; identification</div>
        <div class="profile-field-grid">
          <?php profile_field('National ID Number', 'کۆدی ناسنامە', $student['id_number']); ?>
          <?php profile_field('ID type', 'جۆری ناسنامە', $student['id_type'], true); ?>
          <?php profile_field('Birth date', 'بەرواری لەدایکبوون', $student['birth_date']); ?>
          <?php profile_field('Blood type', 'گروپی خوێن', $student['blood_type']); ?>
          <?php profile_field('Gender', 'رەگەز', gender_label($student['gender'])); ?>
          <?php profile_field('Nationality', 'نەتەوە', $student['nationality'], true); ?>
          <?php profile_field('Citizenship', 'هاوڵاتیبوون', $student['citizenship'], true); ?>
          <?php profile_field('Religion', 'ئاین', $student['religion'], true); ?>
          <?php profile_field('Clothing size', 'سایزی خل و بەرگ', $student['clothing_size'], true); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-people"></i>Family</div>
        <div class="profile-field-grid">
          <?php profile_field("Mother's name", 'ناوی سیانی دایك', $student['mother_name'], true); ?>
          <?php profile_field("Father's phone", 'ژمارە مۆبایل باوك', $student['father_phone']); ?>
          <?php profile_field("Mother's phone", 'ژمارە مۆبایل دایك', $student['mother_phone']); ?>
          <?php profile_field('Brothers', 'ژمارەی برا', $student['brothers_count']); ?>
          <?php profile_field('Sisters', 'ژمارەی خوشك', $student['sisters_count']); ?>
          <?php profile_field('Birth order', 'چەندەمین منداڵە', $student['birth_order']); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-mortarboard"></i>Academic</div>
        <div class="profile-field-grid">
          <?php profile_field('Class section', null, class_section_label($student)); ?>
          <?php profile_field('Previous school', 'کۆتا قوتابخانە', $student['previous_school'], true); ?>
          <?php profile_field('Years in current grade', 'چەندەمین ساڵە لەم پۆلە', $student['years_in_current_grade']); ?>
          <?php profile_field("Last year's average", 'کۆمەڵی نمرەی دوایین ساڵ', $student['last_year_average']); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-telephone"></i>Contact &amp; address</div>
        <div class="profile-field-grid">
          <?php profile_field('Student phone', 'ژمارە مۆبایل قوتابی', $student['phone']); ?>
          <?php profile_field('Student phone 2', null, $student['phone2']); ?>
          <?php profile_field('Home address', 'ناونیشانی مال', $student['home_address'], true); ?>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-images"></i>Additional photos</div>
        <div class="profile-extra-photos">
          <div class="profile-extra-photo-box">
            <div class="frame">
              <?php if ($photoData['extra1']['src']): ?>
                <img src="<?= $photoData['extra1']['src'] ?>" alt="">
              <?php else: ?>
                <i class="bi bi-image"></i>
              <?php endif; ?>
            </div>
            <div class="caption kurdish-text">وێنە ١</div>
          </div>
          <div class="profile-extra-photo-box">
            <div class="frame">
              <?php if ($photoData['extra2']['src']): ?>
                <img src="<?= $photoData['extra2']['src'] ?>" alt="">
              <?php else: ?>
                <i class="bi bi-image"></i>
              <?php endif; ?>
            </div>
            <div class="caption kurdish-text">وێنە ٢</div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 no-print">
      <div class="profile-section">
        <div class="profile-section-title"><i class="bi bi-file-earmark-pdf"></i>Attached document</div>
        <?php if ($student['form_stored_filename']): ?>
          <a href="<?= base_url('admin/download_student_form.php') ?>?id=<?= $studentId ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i><?= h($student['form_original_filename']) ?></a>
        <?php else: ?>
          <div class="text-muted small">No document attached. Add one from the Edit screen or the Students page.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php else: ?>

  <form method="post" enctype="multipart/form-data" class="no-print">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= $studentId ?>">

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="profile-section">
          <div class="profile-section-title"><i class="bi bi-person-badge"></i>Basic info</div>
          <div class="row g-2">
            <div class="col-sm-12">
              <label class="form-label small mb-1">Full name</label>
              <input type="text" name="full_name" class="form-control form-control-sm kurdish-text" value="<?= h(student_full_name($student)) ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Gender <span class="text-muted kurdish-text">رەگەز</span></label>
              <select name="gender" class="form-select form-select-sm">
                <option value="">Not set</option>
                <option value="male" <?= $student['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $student['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">National ID Number <span class="text-muted kurdish-text">کۆدی ناسنامە</span></label>
              <input type="text" name="id_number" class="form-control form-control-sm" value="<?= h($student['id_number']) ?>" required>
              <div class="form-text">Also their login password.</div>
            </div>
            <div class="col-sm-12">
              <label class="form-label small mb-1">Class section</label>
              <select name="class_section_id" class="form-select form-select-sm" required>
                <?php foreach ($sections as $sec): ?>
                  <option value="<?= (int)$sec['id'] ?>" <?= (int)$student['class_section_id'] === (int)$sec['id'] ? 'selected' : '' ?>><?= h(class_section_label($sec)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Student phone <span class="text-muted kurdish-text">ژمارە مۆبایل قوتابی</span></label>
              <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($student['phone']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Student phone 2</label>
              <input type="text" name="phone2" class="form-control form-control-sm" value="<?= h($student['phone2']) ?>">
            </div>
            <div class="col-sm-12">
              <label class="form-label small mb-1">Home address <span class="text-muted kurdish-text">ناونیشانی مال</span></label>
              <input type="text" name="home_address" class="form-control form-control-sm kurdish-text" value="<?= h($student['home_address']) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="profile-section">
          <div class="profile-section-title"><i class="bi bi-person-lines-fill"></i>Personal &amp; identification</div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label small mb-1">ID type <span class="text-muted kurdish-text">جۆری ناسنامە</span></label>
              <select name="id_type" class="form-select form-select-sm kurdish-text">
                <option value="">Not set</option>
                <?php foreach ($idTypes as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $student['id_type'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Birth date <span class="text-muted kurdish-text">بەرواری لەدایکبوون</span></label>
              <input type="date" name="birth_date" class="form-control form-control-sm" value="<?= h($student['birth_date']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Blood type <span class="text-muted kurdish-text">گروپی خوێن</span></label>
              <select name="blood_type" class="form-select form-select-sm">
                <option value="">Not set</option>
                <?php foreach ($bloodTypes as $bt): ?>
                  <option value="<?= h($bt) ?>" <?= $student['blood_type'] === $bt ? 'selected' : '' ?>><?= h($bt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Nationality <span class="text-muted kurdish-text">نەتەوە</span></label>
              <select name="nationality" class="form-select form-select-sm kurdish-text">
                <option value="">Not set</option>
                <?php foreach ($nationalities as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $student['nationality'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Citizenship <span class="text-muted kurdish-text">هاوڵاتیبوون</span></label>
              <select name="citizenship" class="form-select form-select-sm kurdish-text">
                <option value="">Not set</option>
                <?php foreach ($citizenships as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $student['citizenship'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Religion <span class="text-muted kurdish-text">ئاین</span></label>
              <select name="religion" class="form-select form-select-sm kurdish-text">
                <option value="">Not set</option>
                <?php foreach ($religions as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $student['religion'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Clothing size <span class="text-muted kurdish-text">سایزی خل و بەرگ</span></label>
              <select name="clothing_size" class="form-select form-select-sm">
                <option value="">Not set</option>
                <?php foreach ($clothingSizes as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $student['clothing_size'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
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
              <input type="text" name="mother_name" class="form-control form-control-sm kurdish-text" value="<?= h($student['mother_name']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Father's phone <span class="text-muted kurdish-text">ژمارە مۆبایل باوك</span></label>
              <input type="text" name="father_phone" class="form-control form-control-sm" value="<?= h($student['father_phone']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Mother's phone <span class="text-muted kurdish-text">ژمارە مۆبایل دایك</span></label>
              <input type="text" name="mother_phone" class="form-control form-control-sm" value="<?= h($student['mother_phone']) ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-1">Brothers <span class="text-muted kurdish-text">ژمارەی برا</span></label>
              <input type="number" min="0" name="brothers_count" class="form-control form-control-sm" value="<?= h($student['brothers_count']) ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-1">Sisters <span class="text-muted kurdish-text">ژمارەی خوشك</span></label>
              <input type="number" min="0" name="sisters_count" class="form-control form-control-sm" value="<?= h($student['sisters_count']) ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-1">Birth order <span class="text-muted kurdish-text">چەندەمین منداڵە</span></label>
              <input type="number" min="1" name="birth_order" class="form-control form-control-sm" value="<?= h($student['birth_order']) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="profile-section">
          <div class="profile-section-title"><i class="bi bi-mortarboard"></i>Academic</div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label small mb-1">Previous school <span class="text-muted kurdish-text">کۆتا قوتابخانە</span></label>
              <input type="text" name="previous_school" class="form-control form-control-sm kurdish-text" value="<?= h($student['previous_school']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Years in current grade <span class="text-muted kurdish-text">چەندەمین ساڵە لەم پۆلە</span></label>
              <input type="number" min="1" name="years_in_current_grade" class="form-control form-control-sm" value="<?= h($student['years_in_current_grade']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1">Last year's average <span class="text-muted kurdish-text">کۆمەڵی نمرەی دوایین ساڵ</span></label>
              <input type="text" name="last_year_average" class="form-control form-control-sm" value="<?= h($student['last_year_average']) ?>">
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
    </div>

    <button type="submit" class="btn btn-brand mt-4"><i class="bi bi-check-lg me-1"></i>Save profile</button>
  </form>

  <?php
    $photoSlotLabels = ['main' => 'profile photo', 'extra1' => 'extra photo 1', 'extra2' => 'extra photo 2'];
    $anyPhoto = $student['photo_stored_filename'] || $student['extra_photo1_stored_filename'] || $student['extra_photo2_stored_filename'];
  ?>
  <?php if ($anyPhoto): ?>
    <div class="profile-section mt-4 no-print">
      <div class="profile-section-title"><i class="bi bi-trash"></i>Remove a current photo</div>
      <div class="d-flex gap-2 flex-wrap">
        <?php foreach (['main' => $student['photo_stored_filename'], 'extra1' => $student['extra_photo1_stored_filename'], 'extra2' => $student['extra_photo2_stored_filename']] as $slot => $hasPhoto): ?>
          <?php if ($hasPhoto): ?>
            <form method="post" onsubmit="return confirm('Remove <?= h($photoSlotLabels[$slot]) ?>?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_photo">
              <input type="hidden" name="id" value="<?= $studentId ?>">
              <input type="hidden" name="slot" value="<?= h($slot) ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Remove <?= h($photoSlotLabels[$slot]) ?></button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="profile-section mt-4 no-print">
    <div class="profile-section-title"><i class="bi bi-file-earmark-pdf"></i>Attached document</div>
    <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_document">
      <input type="hidden" name="id" value="<?= $studentId ?>">
      <div>
        <label class="form-label small mb-1">Replace document (optional PDF)</label>
        <input type="file" name="form_pdf" accept="application/pdf,.pdf" class="form-control form-control-sm">
      </div>
      <button type="submit" class="btn btn-sm btn-brand">Save</button>
      <?php if ($student['form_stored_filename']): ?>
        <a href="<?= base_url('admin/download_student_form.php') ?>?id=<?= $studentId ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i>View current</a>
      <?php endif; ?>
    </form>
    <?php if ($student['form_stored_filename']): ?>
      <form method="post" class="mt-2" onsubmit="return confirm('Remove the attached document for this student?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_document">
        <input type="hidden" name="id" value="<?= $studentId ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove attached document</button>
      </form>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
