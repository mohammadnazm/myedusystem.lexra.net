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
        $grade = trim($_POST['grade'] ?? '');
        $group = trim($_POST['class_group'] ?? '');

        if (!$departmentId || $grade === '' || $group === '') {
            flash_set('danger', 'All fields are required.');
        } elseif (!department_belongs_to_institute($conn, $departmentId, $instituteId)) {
            flash_set('danger', 'Department not found.');
        } else {
            $stmt = $conn->prepare("INSERT INTO class_sections (department_id, grade, class_group) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $departmentId, $grade, $group);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added class section grade $grade / group $group");
                flash_set('success', 'Class section added.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That section already exists for this department.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!class_section_belongs_to_institute($conn, $id, $instituteId)) {
            flash_set('danger', 'Class section not found.');
        } else {
            $stmt = $conn->prepare("DELETE FROM class_sections WHERE id = ?");
            $stmt->bind_param('i', $id);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted class section #$id");
                flash_set('success', 'Class section deleted.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'Cannot delete: students or teacher assignments still reference this section.');
            }
        }
    }
    header('Location: ' . base_url('admin/sections.php'));
    exit;
}

$departments = all_departments($conn, $instituteId);
$sections = all_class_sections($conn, $instituteId);

$pageTitle = 'Class Sections';
$role = 'admin';
$activePage = 'sections';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-4 content-fill">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-title">Add class section</div>
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
            <label class="form-label">Grade</label>
            <input type="text" name="grade" class="form-control" placeholder="e.g. 10" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Group</label>
            <input type="text" name="class_group" class="form-control" placeholder="e.g. A" required>
          </div>
          <button type="submit" class="btn btn-brand w-100">Add section</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-title">All class sections (<?= count($sections) ?>)</div>
      <?php if (!$sections): ?>
        <div class="empty-state"><i class="bi bi-grid-3x3-gap"></i>No class sections yet.</div>
      <?php else: ?>
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Department</th><th>Grade</th><th>Group</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($sections as $s): ?>
              <tr>
                <td class="fw-semibold"><?= h($s['department_name']) ?></td>
                <td><?= h($s['grade']) ?></td>
                <td><?= h($s['class_group']) ?></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Delete this section?');" class="d-inline">
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
