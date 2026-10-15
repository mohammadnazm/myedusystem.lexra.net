<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

$teacherStmt = $conn->prepare("SELECT id, full_name FROM teachers WHERE institute_id = ? ORDER BY full_name");
$teacherStmt->bind_param('i', $instituteId);
$teacherStmt->execute();
$teachers = $teacherStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$teacherId = isset($_GET['teacher']) && $_GET['teacher'] !== '' ? (int)$_GET['teacher'] : null;
if ($teacherId && !teacher_belongs_to_institute($conn, $teacherId, $instituteId)) {
    $teacherId = null;
}
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
$to = $_GET['to'] ?? date('Y-m-d');

$summary = daily_reports_summary($conn, $instituteId, $teacherId, $from, $to);

$pageTitle = 'Daily Reports';
$role = 'admin';
$activePage = 'daily_reports';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Search reports</div>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label">Teacher</label>
      <select name="teacher" class="form-select">
        <option value="">All teachers</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $teacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-brand w-100">Search</button>
    </div>
  </form>
</div>

<div class="panel mb-0">
  <div class="panel-title">Results (<?= count($summary) ?>)</div>
  <?php if (!$summary): ?>
    <div class="empty-state"><i class="bi bi-journal-text"></i>No reports found for this range.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>Teacher</th><th>Date</th><th>Lessons logged</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($summary as $s): ?>
            <tr>
              <td class="fw-semibold"><?= h($s['teacher_name']) ?></td>
              <td><?= h($s['report_date']) ?></td>
              <td><span class="badge text-bg-primary-subtle text-primary-emphasis"><?= (int)$s['entry_count'] ?></span></td>
              <td class="text-end">
                <a href="<?= base_url('admin/daily_report_detail.php') ?>?teacher=<?= (int)$s['teacher_id'] ?>&date=<?= h($s['report_date']) ?>" class="btn btn-sm btn-brand">
                  <i class="bi bi-eye me-1"></i>View
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
