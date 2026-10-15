<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('student');

$studentId = current_user_id();
$myGrade = student_department_and_grade($conn, $studentId);
$resources = $myGrade ? resources_for_grade($conn, $myGrade['department_id'], $myGrade['grade']) : [];

$pageTitle = 'Class Materials';
$role = 'student';
$activePage = 'resources';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel mb-0">
  <div class="panel-title">PDFs shared by your teachers</div>
  <?php if (!$resources): ?>
    <div class="empty-state"><i class="bi bi-file-earmark-pdf"></i>No materials have been shared for your class yet.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th>Title</th><th>Subject</th><th>Shared by</th><th>Size</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($resources as $r): ?>
            <tr>
              <td class="fw-semibold"><?= h($r['title']) ?></td>
              <td><?= h($r['subject_name']) ?></td>
              <td class="text-muted small"><?= h($r['uploader_name']) ?></td>
              <td class="small text-muted"><?= h(format_file_size((int)$r['file_size'])) ?></td>
              <td class="small text-muted text-nowrap"><?= h($r['uploaded_at']) ?></td>
              <td class="text-end">
                <a href="<?= base_url('download.php') ?>?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-brand"><i class="bi bi-download me-1"></i>Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
