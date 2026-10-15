<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('teacher');

$assignments = teacher_assignments($conn, current_user_id());

$pageTitle = 'Dashboard';
$role = 'teacher';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel content-fill">
  <div class="panel-title">Welcome, <?= h(current_user_name()) ?></div>
  <p class="text-muted">Here are the classes and subjects your admin has assigned to you. You can only enter marks for these.</p>

  <?php if (!$assignments): ?>
    <div class="empty-state"><i class="bi bi-diagram-3"></i>No classes assigned to you yet. Contact your admin.</div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>Department</th><th>Grade</th><th>Group</th><th>Subject</th><th class="text-end"></th></tr></thead>
      <tbody>
        <?php foreach ($assignments as $a): ?>
          <tr>
            <td><?= h($a['department_name']) ?></td>
            <td><?= h($a['grade']) ?></td>
            <td><?= h($a['class_group']) ?></td>
            <td class="fw-semibold"><?= h($a['subject_name']) ?></td>
            <td class="text-end">
              <a href="<?= base_url('teacher/marks.php') ?>?section=<?= (int)$a['class_section_id'] ?>&subject=<?= (int)$a['subject_id'] ?>" class="btn btn-sm btn-brand">
                <i class="bi bi-pencil-square me-1"></i>Enter marks
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
