<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('teacher');

$teacherId = current_user_id();
$assignments = teacher_assignments($conn, $teacherId);

$classSectionId = isset($_GET['section']) ? (int)$_GET['section'] : 0;
$subjectId = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $classSectionId = (int)($_POST['class_section_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $date = (string)($_POST['attendance_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    // Server-side enforcement: never trust the client for the access boundary.
    if (!teacher_can_access($conn, $teacherId, $classSectionId, $subjectId)) {
        flash_set('danger', 'You are not assigned to that class/subject.');
        header('Location: ' . base_url('teacher/attendance.php'));
        exit;
    }

    $studentIds = is_array($_POST['student_id'] ?? null) ? $_POST['student_id'] : [];
    $lesson = trim((string)($_POST['lesson'] ?? ''));
    if ($lesson === '') {
        flash_set('danger', 'Please enter which lesson this is before saving attendance.');
        header('Location: ' . base_url('teacher/attendance.php') . "?section=$classSectionId&subject=$subjectId&date=$date");
        exit;
    }

    $saved = 0;
    $inSection = student_ids_in_section($conn, $classSectionId);
    foreach ($studentIds as $studentId) {
        $studentId = (int)$studentId;
        if (!isset($inSection[$studentId])) {
            continue; // only students actually in this class
        }
        $status = $_POST['status'][$studentId] ?? 'present';
        if (!in_array($status, ATTENDANCE_STATUSES, true)) {
            $status = 'present';
        }
        $remarks = $_POST['remarks'][$studentId] ?? null;
        save_student_attendance($conn, $studentId, $classSectionId, $subjectId, $date, $lesson, $status, $remarks, $teacherId);
        $saved++;
    }
    log_activity($conn, current_institute_id(), 'teacher', $teacherId, current_user_name(), "Took attendance for section #$classSectionId / subject #$subjectId, lesson \"$lesson\", on $date ($saved students)");
    flash_set('success', 'Attendance saved for ' . $date . '.');
    header('Location: ' . base_url('teacher/attendance.php') . "?section=$classSectionId&subject=$subjectId&date=$date");
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
$existingAttendance = [];
$recentDates = [];
$currentLesson = '';
if ($activeAssignment) {
    $students = students_in_section($conn, $classSectionId);
    $existingAttendance = attendance_for_section_subject_date($conn, $classSectionId, $subjectId, $date);
    $recentDates = attendance_recent_dates_for_section($conn, $classSectionId, $subjectId, 8);
    if ($existingAttendance) {
        $currentLesson = (string)reset($existingAttendance)['lesson'];
    }
}

$pageTitle = 'Attendance';
$role = 'teacher';
$activePage = 'attendance';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Select your class, subject & date</div>
  <?php if (!$assignments): ?>
    <div class="empty-state"><i class="bi bi-diagram-3"></i>No classes assigned to you yet. Contact your admin.</div>
  <?php else: ?>
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Class & subject</label>
        <select name="combo" class="form-select" onchange="var v=this.value.split('|'); window.location.href='?section='+v[0]+'&subject='+v[1]+'&date=<?= h($date) ?>';">
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
        <label class="form-label">Date</label>
        <input type="date" name="date" class="form-control" value="<?= h($date) ?>" max="<?= h(date('Y-m-d')) ?>"
               onchange="if(document.querySelector('[name=combo]').value){var v=document.querySelector('[name=combo]').value.split('|'); window.location.href='?section='+v[0]+'&subject='+v[1]+'&date='+this.value;}">
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-brand w-100"><i class="bi bi-search me-1"></i>Load</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php if ($activeAssignment): ?>
  <div class="panel">
    <div class="panel-title d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>
        <?= h($activeAssignment['subject_name']) ?> —
        <?= h($activeAssignment['department_name']) ?>, Grade <?= h($activeAssignment['grade']) ?>, Group <?= h($activeAssignment['class_group']) ?>
        (<?= count($students) ?> students) — <span class="text-muted fw-normal"><?= h(date('l, F j, Y', strtotime($date))) ?></span>
      </span>
      <?php if ($existingAttendance): ?>
        <span class="badge text-bg-primary-subtle text-primary-emphasis"><i class="bi bi-check2-circle me-1"></i>Already recorded (Lesson: <?= h($currentLesson) ?>) — editing will update it</span>
      <?php endif; ?>
    </div>
    <?php if (!$students): ?>
      <div class="empty-state"><i class="bi bi-people"></i>No students in this class yet.</div>
    <?php else: ?>
      <form method="post" id="attendanceForm">
        <?= csrf_field() ?>
        <input type="hidden" name="class_section_id" value="<?= $classSectionId ?>">
        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
        <input type="hidden" name="attendance_date" value="<?= h($date) ?>">
        <div class="row g-2 mb-3">
          <div class="col-md-4">
            <label class="form-label">Lesson <span class="text-danger">*</span></label>
            <input type="text" name="lesson" class="form-control" maxlength="50" required
                   placeholder="e.g. 1st lesson, Period 2" value="<?= h($currentLesson) ?>">
          </div>
        </div>
        <div class="d-flex gap-2 mb-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAll('present')"><i class="bi bi-check-all me-1"></i>Mark all present</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAll('absent')"><i class="bi bi-x-lg me-1"></i>Mark all absent</button>
        </div>
        <div class="table-responsive table-scroll">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Student</th>
                <th>Status</th>
                <th>Remarks (optional)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $st):
                $existing = $existingAttendance[$st['id']] ?? null;
                $currentStatus = $existing['status'] ?? 'present'; ?>
                <tr>
                  <td class="fw-semibold">
                    <?= h(student_full_name($st)) ?>
                    <input type="hidden" name="student_id[]" value="<?= (int)$st['id'] ?>">
                  </td>
                  <td>
                    <div class="attendance-status-group">
                      <?php foreach (ATTENDANCE_STATUSES as $statusOption):
                        $meta = attendance_status_meta($statusOption);
                        $inputId = 'status_' . $st['id'] . '_' . $statusOption; ?>
                        <input type="radio" id="<?= $inputId ?>" name="status[<?= (int)$st['id'] ?>]" value="<?= $statusOption ?>" <?= $currentStatus === $statusOption ? 'checked' : '' ?>>
                        <label for="<?= $inputId ?>"><i class="bi <?= $meta['icon'] ?> me-1"></i><?= h($meta['label']) ?></label>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td>
                    <input type="text" class="form-control form-control-sm attendance-remarks-input" maxlength="255"
                           name="remarks[<?= (int)$st['id'] ?>]" placeholder="e.g. sick note"
                           value="<?= $existing ? h((string)$existing['remarks']) : '' ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="submit" class="btn btn-brand mt-2"><i class="bi bi-save me-1"></i>Save attendance</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($recentDates): ?>
    <div class="panel mb-0">
      <div class="panel-title">Recently taken for this subject</div>
      <div class="table-scroll table-scroll-sm">
        <table class="table">
          <thead><tr><th>Date</th><th>Lesson</th><th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($recentDates as $rd): ?>
              <tr>
                <td class="fw-semibold"><?= h(date('M j, Y', strtotime($rd['attendance_date']))) ?></td>
                <td><?= h($rd['lesson']) ?></td>
                <td><?= (int)$rd['present_count'] ?></td>
                <td><?= (int)$rd['absent_count'] ?></td>
                <td><?= (int)$rd['late_count'] ?></td>
                <td><?= (int)$rd['excused_count'] ?></td>
                <td class="text-end">
                  <a href="<?= base_url('teacher/attendance.php') ?>?section=<?= $classSectionId ?>&subject=<?= $subjectId ?>&date=<?= h($rd['attendance_date']) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil-square me-1"></i>Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
function markAll(status) {
  document.querySelectorAll('#attendanceForm .attendance-status-group input[value="' + status + '"]').forEach(function (el) {
    el.checked = true;
  });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
