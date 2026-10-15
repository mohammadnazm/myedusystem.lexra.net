<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('teacher');

$teacherId = current_user_id();
$assignments = teacher_assignments($conn, $teacherId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_verify();
    if (delete_daily_report($conn, (int)$_POST['delete_id'], $teacherId)) {
        flash_set('success', 'Report deleted.');
    }
    header('Location: ' . base_url('teacher/daily_reports.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $classSectionId = (int)($_POST['class_section_id'] ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $lessonNumber = (int)($_POST['lesson_number'] ?? 0);
    $reportDate = $_POST['report_date'] ?? '';
    $materials = trim($_POST['materials'] ?? '');
    $methods = trim($_POST['methods'] ?? '');
    $contents = trim($_POST['contents'] ?? '');

    if (!teacher_can_access($conn, $teacherId, $classSectionId, $subjectId)) {
        flash_set('danger', 'You are not assigned to that class/subject.');
    } elseif ($lessonNumber < 1 || $lessonNumber > 6 || !$reportDate || $materials === '' || $methods === '' || $contents === '') {
        flash_set('danger', 'Please fill in every field.');
    } else {
        save_daily_report($conn, $teacherId, $classSectionId, $subjectId, $lessonNumber, $reportDate, $materials, $methods, $contents);
        log_activity($conn, current_institute_id(), 'teacher', $teacherId, current_user_name(), "Logged a daily report for $reportDate, period $lessonNumber");
        flash_set('success', 'Daily report saved.');
    }
    header('Location: ' . base_url('teacher/daily_reports.php'));
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$reports = daily_reports_for_teacher($conn, $teacherId, $from, $to);

$pageTitle = 'Daily Reports';
$role = 'teacher';
$activePage = 'daily_reports';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<?php if (!$assignments): ?>
  <div class="panel"><div class="empty-state"><i class="bi bi-diagram-3"></i>No classes assigned to you yet. Contact your admin.</div></div>
<?php else: ?>

  <div class="panel">
    <div class="panel-title">Log today's activity</div>
    <form method="post" class="row g-3">
      <?= csrf_field() ?>
      <div class="col-md-4">
        <label class="form-label">Date</label>
        <input type="date" name="report_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Period</label>
        <select name="lesson_number" class="form-select" required>
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <option value="<?= $i ?>">Period <?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Class & subject</label>
        <select name="combo" id="combo_select" class="form-select" required onchange="
          var v=this.value.split('|');
          document.getElementById('class_section_id').value = v[0] || '';
          document.getElementById('subject_id').value = v[1] || '';
        ">
          <option value="">Select...</option>
          <?php foreach ($assignments as $a): ?>
            <option value="<?= (int)$a['class_section_id'] ?>|<?= (int)$a['subject_id'] ?>">
              <?= h($a['department_name']) ?> — Grade <?= h($a['grade']) ?> — Group <?= h($a['class_group']) ?> — <?= h($a['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="class_section_id" id="class_section_id">
        <input type="hidden" name="subject_id" id="subject_id">
      </div>
      <div class="col-md-6">
        <label class="form-label">Materials used</label>
        <input type="text" name="materials" class="form-control" placeholder="e.g. Textbook chapter 4, whiteboard" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Methods used</label>
        <input type="text" name="methods" class="form-control" placeholder="e.g. Lecture, group discussion" required>
      </div>
      <div class="col-12">
        <label class="form-label">Contents / what was covered</label>
        <textarea name="contents" class="form-control" rows="3" placeholder="Describe what you covered in this lesson..." required></textarea>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-brand"><i class="bi bi-plus-lg me-1"></i>Save report</button>
      </div>
    </form>
  </div>

  <div class="panel mb-0">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div class="panel-title mb-0">My reports</div>
      <form method="get" class="d-flex gap-2 align-items-end">
        <div>
          <label class="form-label small mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>">
        </div>
        <div>
          <label class="form-label small mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
      </form>
    </div>

    <?php if (!$reports): ?>
      <div class="empty-state"><i class="bi bi-journal-text"></i>No reports logged in this date range.</div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="table">
          <thead>
            <tr><th>Date</th><th>Period</th><th>Class</th><th>Subject</th><th>Materials</th><th>Methods</th><th>Contents</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($reports as $r): ?>
              <tr>
                <td class="text-nowrap"><?= h($r['report_date']) ?></td>
                <td>P<?= (int)$r['lesson_number'] ?></td>
                <td class="small"><?= h($r['department_name']) ?> · G<?= h($r['grade']) ?>-<?= h($r['class_group']) ?></td>
                <td class="fw-semibold"><?= h($r['subject_name']) ?></td>
                <td class="small"><?= h($r['materials']) ?></td>
                <td class="small"><?= h($r['methods']) ?></td>
                <td class="small" style="max-width:260px;"><?= h($r['contents']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this report?');"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
