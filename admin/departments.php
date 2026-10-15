<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('danger', 'Department name is required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO departments (institute_id, name) VALUES (?, ?)");
            $stmt->bind_param('is', $instituteId, $name);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added department \"$name\"");
                flash_set('success', 'Department added.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That department already exists.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!department_belongs_to_institute($conn, $id, $instituteId)) {
            flash_set('danger', 'Department not found.');
        } else {
            $stmt = $conn->prepare("DELETE FROM departments WHERE id = ? AND institute_id = ?");
            $stmt->bind_param('ii', $id, $instituteId);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted department #$id");
                flash_set('success', 'Department deleted.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'Cannot delete: this department still has class sections or subjects linked to it.');
            }
        }
    }
    header('Location: ' . base_url('admin/departments.php'));
    exit;
}

$departments = all_departments($conn, $instituteId);

$pageTitle = 'Departments';
$role = 'admin';
$activePage = 'departments';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-4 content-fill">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-title">Add department</div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="mb-3">
          <label class="form-label">Department name</label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Computer Science" required>
        </div>
        <button type="submit" class="btn btn-brand w-100">Add department</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-title">All departments (<?= count($departments) ?>)</div>
      <?php if (!$departments): ?>
        <div class="empty-state"><i class="bi bi-diagram-3"></i>No departments yet. Add your first one.</div>
      <?php else: ?>
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Name</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($departments as $d): ?>
              <tr>
                <td class="fw-semibold"><?= h($d['name']) ?></td>
                <td class="text-muted"><?= h($d['created_at']) ?></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Delete this department? This only works if no sections/subjects use it.');" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
