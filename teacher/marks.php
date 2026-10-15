<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('teacher');

$teacherId = current_user_id();
$assignments = teacher_assignments($conn, $teacherId);

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

    // Server-side enforcement: never trust the client for the access boundary.
    if (!teacher_can_access($conn, $teacherId, $classSectionId, $subjectId)) {
        flash_set('danger', 'You are not assigned to that class/subject.');
        header('Location: ' . base_url('teacher/marks.php'));
        exit;
    }

    // Only students who are really in this class can be written to — a tampered
    // form must never be able to touch another class's (or institute's) marks.
    $studentIds = is_array($_POST['student_id'] ?? null) ? $_POST['student_id'] : [];
    $inSection = student_ids_in_section($conn, $classSectionId);
    foreach ($studentIds as $studentId) {
        $studentId = (int)$studentId;
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
        save_student_marks($conn, $studentId, $subjectId, $semester, $marks, $teacherId);
    }
    log_activity($conn, current_institute_id(), 'teacher', $teacherId, current_user_name(), "Saved marks for section #$classSectionId / subject #$subjectId ($semester term)");
    flash_set('success', 'Marks saved.');
    header('Location: ' . base_url('teacher/marks.php') . "?section=$classSectionId&subject=$subjectId&semester=$semester");
    exit;
}

// Validate the requested section/subject is actually one of this teacher's assignments.
$activeAssignment = null;
foreach ($assignments as $a) {
    if ((int)$a['class_section_id'] === $classSectionId && (int)$a['subject_id'] === $subjectId) {
        $activeAssignment = $a;
        break;
    }
}
if ($classSectionId && $subjectId && !$activeAssignment) {
    flash_set('danger', 'You are not assigned to that class/subject.');
    $classSectionId = 0;
    $subjectId = 0;
}

$students = [];
$existingMarks = [];
if ($activeAssignment) {
    $students = students_in_section($conn, $classSectionId);
    $existingMarks = marks_for_section($conn, $classSectionId, $subjectId, $semester);
}

$pageTitle = 'Marks Entry';
$role = 'teacher';
$activePage = 'marks';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Select your class & subject</div>
  <?php if (!$assignments): ?>
    <div class="empty-state"><i class="bi bi-diagram-3"></i>No classes assigned to you yet. Contact your admin.</div>
  <?php else: ?>
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Class & subject</label>
        <select name="combo" class="form-select" onchange="var v=this.value.split('|'); window.location.href='?section='+v[0]+'&subject='+v[1]+'&semester=<?= h($semester) ?>';">
          <option value="">Select...</option>
          <?php foreach ($assignments as $a): ?>
            <option value="<?= (int)$a['class_section_id'] ?>|<?= (int)$a['subject_id'] ?>"
              <?= ($classSectionId === (int)$a['class_section_id'] && $subjectId === (int)$a['subject_id']) ? 'selected' : '' ?>>
              <?= h($a['department_name']) ?> — Grade <?= h($a['grade']) ?> — Group <?= h($a['class_group']) ?> — <?= h($a['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Semester</label>
        <select name="semester" class="form-select" onchange="if(document.querySelector('[name=combo]').value){var v=document.querySelector('[name=combo]').value.split('|'); window.location.href='?section='+v[0]+'&subject='+v[1]+'&semester='+this.value;}">
          <option value="first" <?= $semester === 'first' ? 'selected' : '' ?>>First term</option>
          <option value="second" <?= $semester === 'second' ? 'selected' : '' ?>>Second term</option>
        </select>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php if ($activeAssignment): ?>
  <div class="panel">
    <div class="panel-title">
      <?= h($activeAssignment['subject_name']) ?> —
      <?= h($activeAssignment['department_name']) ?>, Grade <?= h($activeAssignment['grade']) ?>, Group <?= h($activeAssignment['class_group']) ?>
      (<?= count($students) ?> students)
    </div>
    <?php if (!$students): ?>
      <div class="empty-state"><i class="bi bi-people"></i>No students in this class yet.</div>
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
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
