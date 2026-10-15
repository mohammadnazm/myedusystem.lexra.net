<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('teacher');

const MAX_FILE_BYTES = 20 * 1024 * 1024; // 20 MB

$teacherId = current_user_id();
$options = teacher_grade_subject_options($conn, $teacherId);
$uploadDir = __DIR__ . '/../uploads/resources/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'upload') {
    csrf_verify();
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $grade = trim($_POST['grade'] ?? '');
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');

    if (!teacher_can_access_grade_subject($conn, $teacherId, $departmentId, $grade, $subjectId)) {
        flash_set('danger', 'You are not assigned to that grade/subject.');
    } elseif ($title === '') {
        flash_set('danger', 'Please give the file a title.');
    } elseif (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Please choose a PDF file to upload.');
    } else {
        $file = $_FILES['pdf'];

        if ($file['size'] > MAX_FILE_BYTES) {
            flash_set('danger', 'That file is too large (max 20 MB).');
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($mime !== 'application/pdf' || $extension !== 'pdf') {
                flash_set('danger', 'Only PDF files are allowed.');
            } else {
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $storedFilename = bin2hex(random_bytes(20)) . '.pdf';
                $destination = $uploadDir . $storedFilename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    save_resource($conn, $teacherId, null, $departmentId, $grade, $subjectId, $title, $file['name'], $storedFilename, (int)$file['size']);
                    log_activity($conn, current_institute_id(), 'teacher', $teacherId, current_user_name(), "Uploaded a PDF resource: \"$title\"");
                    flash_set('success', 'PDF uploaded — visible to every group in that grade now.');
                } else {
                    flash_set('danger', 'Upload failed. Check that the uploads/resources folder is writable on the server.');
                }
            }
        }
    }
    header('Location: ' . base_url('teacher/resources.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    csrf_verify();
    $resource = delete_resource($conn, (int)($_POST['id'] ?? 0), $teacherId);
    if ($resource) {
        @unlink($uploadDir . $resource['stored_filename']);
        flash_set('success', 'Resource deleted.');
    }
    header('Location: ' . base_url('teacher/resources.php'));
    exit;
}

$resources = resources_for_teacher($conn, $teacherId);

$pageTitle = 'PDF Resources';
$role = 'teacher';
$activePage = 'resources';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<?php if (!$options): ?>
  <div class="panel"><div class="empty-state"><i class="bi bi-diagram-3"></i>No classes assigned to you yet. Contact your admin.</div></div>
<?php else: ?>

  <div class="panel">
    <div class="panel-title">Upload a PDF</div>
    <p class="text-muted small mb-3">PDFs are shared with an entire grade — every group in that grade will see it, not just one.</p>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="upload">
      <div class="col-md-6">
        <label class="form-label">Grade & subject</label>
        <select name="combo" class="form-select" required onchange="
          var v=this.value.split('|');
          document.getElementById('department_id').value = v[0] || '';
          document.getElementById('grade').value = v[1] || '';
          document.getElementById('subject_id').value = v[2] || '';
        ">
          <option value="">Select...</option>
          <?php foreach ($options as $o): ?>
            <option value="<?= (int)$o['department_id'] ?>|<?= h($o['grade']) ?>|<?= (int)$o['subject_id'] ?>">
              <?= h($o['department_name']) ?> — Grade <?= h($o['grade']) ?> — <?= h($o['subject_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="department_id" id="department_id">
        <input type="hidden" name="grade" id="grade">
        <input type="hidden" name="subject_id" id="subject_id">
      </div>
      <div class="col-md-6">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Chapter 5 notes" required>
      </div>
      <div class="col-md-8">
        <label class="form-label">PDF file (max 20 MB)</label>
        <input type="file" name="pdf" accept="application/pdf,.pdf" class="form-control" required>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <button type="submit" class="btn btn-brand w-100"><i class="bi bi-upload me-1"></i>Upload</button>
      </div>
    </form>
  </div>

  <div class="panel mb-0">
    <div class="panel-title">My uploads (<?= count($resources) ?>)</div>
    <?php if (!$resources): ?>
      <div class="empty-state"><i class="bi bi-file-earmark-pdf"></i>You haven't uploaded any PDFs yet.</div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Title</th><th>Grade</th><th>Subject</th><th>Size</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($resources as $r): ?>
              <tr>
                <td class="fw-semibold"><?= h($r['title']) ?></td>
                <td class="small"><?= h($r['department_name']) ?> · Grade <?= h($r['grade']) ?></td>
                <td><?= h($r['subject_name']) ?></td>
                <td class="small text-muted"><?= h(format_file_size((int)$r['file_size'])) ?></td>
                <td class="small text-muted text-nowrap"><?= h($r['uploaded_at']) ?></td>
                <td class="text-end">
                  <a href="<?= base_url('download.php') ?>?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this PDF?');"><?= csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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

