<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();
$adminId = current_user_id();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$returnTo = $_GET['return'] ?? $_POST['return'] ?? base_url('admin/attendance.php');
// Only ever redirect back into our own app.
if (strpos($returnTo, base_url('admin/attendance.php')) !== 0) {
    $returnTo = base_url('admin/attendance.php');
}

$record = attendance_record_for_admin($conn, $id, $instituteId);
if (!$record) {
    flash_set('danger', 'That attendance record was not found.');
    header('Location: ' . base_url('admin/attendance.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'delete') {
        delete_attendance_record($conn, $id, $instituteId);
        log_activity($conn, $instituteId, 'admin', $adminId, current_user_name(), "Deleted attendance record #$id");
        flash_set('success', 'Attendance record deleted.');
        header('Location: ' . $returnTo);
        exit;
    }

    $lesson = trim($_POST['lesson'] ?? '');
    $status = $_POST['status'] ?? 'present';
    $remarks = $_POST['remarks'] ?? null;

    if ($lesson === '') {
        flash_set('danger', 'Lesson is required.');
    } else {
        update_attendance_record($conn, $id, $lesson, $status, $remarks);
        log_activity($conn, $instituteId, 'admin', $adminId, current_user_name(), "Edited attendance record #$id");
        flash_set('success', 'Attendance record updated.');
        header('Location: ' . $returnTo);
        exit;
    }
}

$fullName = trim(implode(' ', array_filter([$record['first_name'], $record['second_name'], $record['last_name']])));

$pageTitle = 'Edit Attendance';
$role = 'admin';
$activePage = 'attendance';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel" style="max-width: 560px;">
  <div class="panel-title">Edit attendance record</div>
  <p class="text-muted">
    <?= h($fullName) ?> — <?= h($record['department_name']) ?>, Grade <?= h($record['grade']) ?>, Group <?= h($record['class_group']) ?><br>
    <?= h($record['subject_name']) ?> on <?= h(date('l, F j, Y', strtotime($record['attendance_date']))) ?>
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
    <input type="hidden" name="return" value="<?= h($returnTo) ?>">

    <div class="mb-3">
      <label class="form-label">Lesson <span class="text-danger">*</span></label>
      <input type="text" name="lesson" class="form-control" maxlength="50" required value="<?= h($record['lesson']) ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php foreach (ATTENDANCE_STATUSES as $statusOption): $meta = attendance_status_meta($statusOption); ?>
          <option value="<?= $statusOption ?>" <?= $record['status'] === $statusOption ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label">Remarks</label>
      <input type="text" name="remarks" class="form-control" maxlength="255" value="<?= h((string)($record['remarks'] ?? '')) ?>">
    </div>

    <div class="d-flex justify-content-between">
      <a href="<?= h($returnTo) ?>" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-brand"><i class="bi bi-save me-1"></i>Save changes</button>
    </div>
  </form>

  <hr>
  <form method="post" onsubmit="return confirm('Delete this attendance record permanently? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
    <input type="hidden" name="return" value="<?= h($returnTo) ?>">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Delete this record</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
