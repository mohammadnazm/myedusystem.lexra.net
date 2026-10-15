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
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (!$departmentId || $name === '') {
            flash_set('danger', 'All fields are required.');
        } elseif (!department_belongs_to_institute($conn, $departmentId, $instituteId)) {
            flash_set('danger', 'Department not found.');
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (department_id, name) VALUES (?, ?)");
            $stmt->bind_param('is', $departmentId, $name);
            $stmt->execute();
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added subject \"$name\"");
            flash_set('success', 'Subject added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!subject_belongs_to_institute($conn, $id, $instituteId)) {
            flash_set('danger', 'Subject not found.');
        } else {
            $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
            $stmt->bind_param('i', $id);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted subject #$id");
                flash_set('success', 'Subject deleted.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'Cannot delete: marks or teacher assignments still reference this subject.');
            }
        }
    }
    header('Location: ' . base_url('admin/subjects.php'));
    exit;
}

$departments = all_departments($conn, $instituteId);
$stmt = $conn->prepare(
    "SELECT sub.*, d.name AS department_name FROM subjects sub
     JOIN departments d ON d.id = sub.department_id
     WHERE d.institute_id = ?
     ORDER BY d.name, sub.name"
);
$stmt->bind_param('i', $instituteId);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Subjects';
$role = 'admin';
$activePage = 'subjects';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-4 content-fill">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-title">Add subject</div>
      <?php if (!$departments): ?>
        <div class="alert alert-warning py-2">Add a department first.</div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select" required>
              <option value="">Select...</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Subject name</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Mathematics" required>
          </div>
          <button type="submit" class="btn btn-brand w-100">Add subject</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-title">All subjects (<?= count($subjects) ?>)</div>
      <?php if (!$subjects): ?>
        <div class="empty-state"><i class="bi bi-journal-bookmark"></i>No subjects yet.</div>
      <?php else: ?>
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Subject</th><th>Department</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($subjects as $s): ?>
              <tr>
                <td class="fw-semibold"><?= h($s['name']) ?></td>
                <td><?= h($s['department_name']) ?></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Delete this subject?');" class="d-inline">
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
