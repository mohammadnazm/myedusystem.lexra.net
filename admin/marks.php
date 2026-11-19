<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

$sections = all_class_sections($conn, $instituteId);
$departments = all_departments($conn, $instituteId);

$classSectionId = isset($_GET['section']) ? (int)$_GET['section'] : 0;
$subjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
$semester = $_GET['semester'] ?? 'first';
if (!in_array($semester, ['first', 'second'], true)) {
    $semester = 'first';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $classSectionId = (int)($_POST['class_section_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $semester = in_array($_POST['semester'] ?? '', ['first', 'second'], true) ? $_POST['semester'] : 'first';

    if (!class_section_belongs_to_institute($conn, $classSectionId, $instituteId)
        || !subject_belongs_to_institute($conn, $subjectId, $instituteId)
        || !subject_in_class_section($conn, $subjectId, $classSectionId)) {
        flash_set('danger', 'Class section or subject not found.');
        header('Location: ' . base_url('admin/marks.php'));
        exit;
    }

    $studentIds = is_array($_POST['student_id'] ?? null) ? $_POST['student_id'] : [];
    $inSection = student_ids_in_section($conn, $classSectionId);
    $saved = 0;
    foreach ($studentIds as $studentId) {
        $studentId = (int)$studentId;
        // The student must sit in the class section being edited (which itself was
        // verified above to belong to this institute) — a tampered form can't write
        // marks for anyone else.
        if (!isset($inSection[$studentId])) {
            continue;
        }
        $marks = [
            'quiz_assignment' => parse_mark($_POST['quiz_assignment'][$studentId] ?? null, MARK_FIELD_MAX['quiz_assignment']),
            'participation'   => parse_mark($_POST['participation'][$studentId] ?? null, MARK_FIELD_MAX['participation']),
            'first_term'      => parse_mark($_POST['first_term'][$studentId] ?? null, MARK_FIELD_MAX['first_term']),
            'second_term'     => parse_mark($_POST['second_term'][$studentId] ?? null, MARK_FIELD_MAX['second_term']),
            'final'           => parse_mark($_POST['final'][$studentId] ?? null, MARK_FIELD_MAX['final']),
        ];
        save_student_marks($conn, $studentId, $subjectId, $semester, $marks, null);
        $saved++;
    }
    log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Saved marks for section #$classSectionId / subject #$subjectId ($semester term)");
    flash_set('success', 'Marks saved.');
    header('Location: ' . base_url('admin/marks.php') . "?section=$classSectionId&subject=$subjectId&semester=$semester");
    exit;
}

$students = [];
$existingMarks = [];
$subjectsForSection = [];
$selectedSectionDept = null;

if ($classSectionId && class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) {
    foreach ($sections as $s) {
        if ((int)$s['id'] === $classSectionId) {
            $selectedSectionDept = (int)$s['department_id'];
            break;
        }
    }
    if ($selectedSectionDept) {
        $subjectsForSection = subjects_for_department($conn, $selectedSectionDept, $instituteId);
    }
    $students = students_in_section($conn, $classSectionId);
    if ($subjectId && subject_belongs_to_institute($conn, $subjectId, $instituteId)) {
        $existingMarks = marks_for_section($conn, $classSectionId, $subjectId, $semester);
    }
} else {
    $classSectionId = 0;
}

$pageTitle = 'Marks Entry';
$role = 'admin';
$activePage = 'marks';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Select class, subject & semester</div>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label">Class section</label>
      <select name="section" id="section_select" class="form-select" onchange="this.form.submit()" required>
        <option value="">Select...</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $classSectionId === (int)$s['id'] ? 'selected' : '' ?>><?= h(class_section_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Subject</label>
      <select name="subject" class="form-select" onchange="this.form.submit()" <?= $classSectionId ? '' : 'disabled' ?>>
        <option value="">Select...</option>
        <?php foreach ($subjectsForSection as $sub): ?>
          <option value="<?= (int)$sub['id'] ?>" <?= $subjectId === (int)$sub['id'] ? 'selected' : '' ?>><?= h($sub['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Semester</label>
      <select name="semester" class="form-select" onchange="this.form.submit()">
        <option value="first" <?= $semester === 'first' ? 'selected' : '' ?>>First term</option>
        <option value="second" <?= $semester === 'second' ? 'selected' : '' ?>>Second term</option>
      </select>
    </div>
  </form>
</div>

<?php if ($classSectionId && $subjectId): ?>
  <div class="panel">
    <div class="panel-title">Enter marks (<?= count($students) ?> students)</div>
    <?php if (!$students): ?>
      <div class="empty-state"><i class="bi bi-people"></i>No students in this class section yet.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="class_section_id" value="<?= $classSectionId ?>">
        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
        <input type="hidden" name="semester" value="<?= h($semester) ?>">
        <div class="table-responsive table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Quiz (/10)</th>
                <th>Participation (/10)</th>
                <th>1st Term (/20)</th>
                <th>2nd Term (/20)</th>
                <th>Final (/60)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $st):
                $existing = $existingMarks[$st['id']] ?? null; ?>
                <tr>
                  <td class="fw-semibold">
                    <?= h(student_full_name($st)) ?>
                    <input type="hidden" name="student_id[]" value="<?= (int)$st['id'] ?>">
                  </td>
                  <?php foreach (['quiz_assignment', 'participation', 'first_term', 'second_term', 'final'] as $field): ?>
                    <td>
                      <input type="number" step="0.01" min="0" max="<?= MARK_FIELD_MAX[$field] ?>" class="form-control form-control-sm mark-input"
                             name="<?= $field ?>[<?= (int)$st['id'] ?>]"
                             value="<?= $existing && $existing[$field] !== null ? h((string)$existing[$field]) : '' ?>">
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="submit" class="btn btn-brand mt-2"><i class="bi bi-save me-1"></i>Save all marks</button>
      </form>
    <?php endif; ?>
  </div>
<?php elseif ($classSectionId): ?>
  <div class="panel"><div class="empty-state"><i class="bi bi-journal-bookmark"></i>Choose a subject to enter marks.</div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
