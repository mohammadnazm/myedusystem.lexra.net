<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

$teacherId = (int)($_GET['teacher'] ?? 0);
$date = $_GET['date'] ?? '';

if (!teacher_belongs_to_institute($conn, $teacherId, $instituteId)) {
    flash_set('danger', 'Teacher not found.');
    header('Location: ' . base_url('admin/daily_reports.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    csrf_verify();
    $deleteId = (int)$_POST['delete'];
    delete_daily_report($conn, $deleteId, $teacherId);
    log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted a daily report entry #" . $deleteId);
    flash_set('success', 'Report entry deleted.');
    header('Location: ' . base_url('admin/daily_report_detail.php') . "?teacher=$teacherId&date=" . urlencode($date));
    exit;
}

$stmt = $conn->prepare("SELECT full_name FROM teachers WHERE id = ?");
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

$entries = ($teacherId && $date) ? daily_reports_detail($conn, $teacherId, $date) : [];

$pageTitle = 'Daily Report Detail';
$role = 'admin';
$activePage = 'daily_reports';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<a href="<?= base_url('admin/daily_reports.php') ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back to search</a>

<div class="panel mb-0">
  <div class="panel-title">
    <?= $teacher ? h($teacher['full_name']) : 'Unknown teacher' ?> — <?= h($date) ?>
  </div>
  <?php if (!$entries): ?>
    <div class="empty-state"><i class="bi bi-journal-text"></i>No entries found.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr><th>Period</th><th>Class</th><th>Subject</th><th>Materials</th><th>Methods</th><th>Contents</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td>P<?= (int)$e['lesson_number'] ?></td>
              <td class="small"><?= h($e['department_name']) ?> · G<?= h($e['grade']) ?>-<?= h($e['class_group']) ?></td>
              <td class="fw-semibold"><?= h($e['subject_name']) ?></td>
              <td class="small"><?= h($e['materials']) ?></td>
              <td class="small"><?= h($e['methods']) ?></td>
              <td class="small" style="max-width:320px;"><?= h($e['contents']) ?></td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this report entry?');"><?= csrf_field() ?><input type="hidden" name="delete" value="<?= (int)$e['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
