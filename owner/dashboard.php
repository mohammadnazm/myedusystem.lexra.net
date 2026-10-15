<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('owner');

const MAX_LOGO_BYTES = 20 * 1024 * 1024; // 20 MB
const MAX_TEMPLATE_BYTES = 15 * 1024 * 1024; // 15 MB
$logoUploadDir = __DIR__ . '/../uploads/logos/';
$templateUploadDir = __DIR__ . '/../uploads/profile_templates/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_institute') {
        $name = trim($_POST['institute_name'] ?? '');
        $adminFullName = trim($_POST['admin_full_name'] ?? '');
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';

        $logoError = null;
        $hasLogo = !empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasLogo) {
            $logoError = validate_uploaded_image($_FILES['logo'], MAX_LOGO_BYTES);
        }

        if ($name === '' || $adminFullName === '' || $adminUsername === '' || strlen($adminPassword) < 6) {
            flash_set('danger', 'Please fill in every field; admin password needs 6+ characters.');
        } elseif ($logoError) {
            flash_set('danger', $logoError);
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO institutes (name) VALUES (?)");
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $instituteId = $conn->insert_id;

                $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admins (institute_id, username, password_hash, full_name) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isss', $instituteId, $adminUsername, $hash, $adminFullName);
                $stmt->execute();

                $conn->commit();

                if ($hasLogo) {
                    $stored = store_uploaded_image($_FILES['logo'], $logoUploadDir);
                    if ($stored) {
                        save_institute_logo($conn, $instituteId, $_FILES['logo']['name'], $stored);
                    }
                }

                flash_set('success', "Institute \"$name\" created with admin account \"$adminUsername\".");
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                flash_set('danger', 'Could not create institute — that admin username may already be taken.');
            }
        }
    } elseif ($action === 'upload_logo') {
        $id = (int)($_POST['id'] ?? 0);
        $institute = get_institute($conn, $id);
        if (!$institute) {
            flash_set('danger', 'Institute not found.');
        } elseif (empty($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
            flash_set('danger', 'Please choose an image to upload.');
        } else {
            $logoError = validate_uploaded_image($_FILES['logo'], MAX_LOGO_BYTES);
            if ($logoError) {
                flash_set('danger', $logoError);
            } else {
                $oldStored = clear_institute_logo($conn, $id);
                if ($oldStored) {
                    @unlink($logoUploadDir . $oldStored);
                }
                $stored = store_uploaded_image($_FILES['logo'], $logoUploadDir);
                if ($stored) {
                    save_institute_logo($conn, $id, $_FILES['logo']['name'], $stored);
                    flash_set('success', 'Logo updated.');
                } else {
                    flash_set('danger', 'Upload failed. Check that the uploads/logos folder is writable on the server.');
                }
            }
        }
        header('Location: ' . base_url('owner/dashboard.php') . '?view=' . $id);
        exit;
    } elseif ($action === 'remove_logo') {
        $id = (int)($_POST['id'] ?? 0);
        $oldStored = clear_institute_logo($conn, $id);
        if ($oldStored) {
            @unlink($logoUploadDir . $oldStored);
        }
        flash_set('success', 'Logo removed.');
        header('Location: ' . base_url('owner/dashboard.php') . '?view=' . $id);
        exit;
    } elseif ($action === 'upload_profile_template') {
        $id = (int)($_POST['id'] ?? 0);
        $institute = get_institute($conn, $id);
        if (!$institute) {
            flash_set('danger', 'Institute not found.');
        } elseif (empty($_FILES['profile_template']) || $_FILES['profile_template']['error'] === UPLOAD_ERR_NO_FILE) {
            flash_set('danger', 'Please choose a PDF to upload.');
        } else {
            $templateError = validate_uploaded_pdf($_FILES['profile_template'], MAX_TEMPLATE_BYTES);
            if ($templateError) {
                flash_set('danger', $templateError);
            } else {
                $oldStored = clear_institute_profile_template($conn, $id);
                if ($oldStored) {
                    @unlink($templateUploadDir . $oldStored);
                }
                $stored = store_uploaded_pdf($_FILES['profile_template'], $templateUploadDir);
                if ($stored) {
                    save_institute_profile_template($conn, $id, $_FILES['profile_template']['name'], $stored, (int)$_FILES['profile_template']['size']);
                    flash_set('success', 'Student profile form template updated.');
                } else {
                    flash_set('danger', 'Upload failed. Check that the uploads/profile_templates folder is writable on the server.');
                }
            }
        }
        header('Location: ' . base_url('owner/dashboard.php') . '?view=' . $id);
        exit;
    } elseif ($action === 'remove_profile_template') {
        $id = (int)($_POST['id'] ?? 0);
        $oldStored = clear_institute_profile_template($conn, $id);
        if ($oldStored) {
            @unlink($templateUploadDir . $oldStored);
        }
        flash_set('success', 'Student profile form template removed.');
        header('Location: ' . base_url('owner/dashboard.php') . '?view=' . $id);
        exit;
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE institutes SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash_set('success', 'Institute status updated.');
    } elseif ($action === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('danger', 'Institute name cannot be empty.');
        } else {
            $stmt = $conn->prepare("UPDATE institutes SET name = ? WHERE id = ?");
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            flash_set('success', 'Institute renamed.');
        }
    } elseif ($action === 'add_admin') {
        $instituteId = (int)($_POST['institute_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$instituteId || $fullName === '' || $username === '' || strlen($password) < 6) {
            flash_set('danger', 'Please fill in every field; password needs 6+ characters.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (institute_id, username, password_hash, full_name) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $instituteId, $username, $hash, $fullName);
            try {
                $stmt->execute();
                flash_set('success', 'Admin account added.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That username is already taken.');
            }
        }
    } elseif ($action === 'reset_admin_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 6) {
            flash_set('danger', 'Password must be at least 6 characters.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            flash_set('success', 'Admin password reset.');
        }
    } elseif ($action === 'delete_admin') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash_set('success', 'Admin account removed.');
    }
    header('Location: ' . base_url('owner/dashboard.php') . (isset($_POST['institute_id']) ? '?view=' . (int)$_POST['institute_id'] : ''));
    exit;
}

$institutes = all_institutes($conn);
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;
$viewInstitute = $viewId ? get_institute($conn, $viewId) : null;
$viewAdmins = $viewInstitute ? admins_for_institute($conn, $viewId) : [];

$pageTitle = 'Institutes';
$role = 'owner';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>

<?php if ($viewInstitute): ?>
  <a href="<?= base_url('owner/dashboard.php') ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back to all institutes</a>

  <div class="panel">
    <div class="panel-title">Logo</div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <?php if ($viewInstitute['logo_stored_filename']): ?>
        <img src="<?= h(base_url('uploads/logos/' . $viewInstitute['logo_stored_filename'])) ?>" alt="" style="width:64px;height:64px;object-fit:contain;border-radius:8px;border:1px solid var(--ink-100);">
      <?php else: ?>
        <div class="text-muted"><i class="bi bi-building" style="font-size:2rem;"></i></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_logo">
        <input type="hidden" name="id" value="<?= (int)$viewInstitute['id'] ?>">
        <div>
          <label class="form-label small mb-1">Upload / replace logo</label>
          <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-brand">Save</button>
      </form>
      <?php if ($viewInstitute['logo_stored_filename']): ?>
        <form method="post" onsubmit="return confirm('Remove this institute\'s logo?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_logo">
          <input type="hidden" name="id" value="<?= (int)$viewInstitute['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">Remove logo</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">Student profile form template</div>
    <p class="text-muted small">A PDF of this institute's own paper enrollment form (like the example you attach here) — shown to the institute's admins as a reference on each student's Profile page, next to the same fields you can fill in there.</p>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <?php if ($viewInstitute['profile_template_stored_filename']): ?>
        <a href="<?= base_url('owner/download_profile_template.php') ?>?id=<?= (int)$viewInstitute['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= h($viewInstitute['profile_template_original_filename']) ?></a>
      <?php else: ?>
        <div class="text-muted"><i class="bi bi-file-earmark-pdf" style="font-size:1.5rem;"></i></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_profile_template">
        <input type="hidden" name="id" value="<?= (int)$viewInstitute['id'] ?>">
        <div>
          <label class="form-label small mb-1">Upload / replace template</label>
          <input type="file" name="profile_template" accept="application/pdf,.pdf" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-brand">Save</button>
      </form>
      <?php if ($viewInstitute['profile_template_stored_filename']): ?>
        <form method="post" onsubmit="return confirm('Remove this institute\'s profile form template?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_profile_template">
          <input type="hidden" name="id" value="<?= (int)$viewInstitute['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">Remove template</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">Rename institute</div>
    <form method="post" class="d-flex gap-2 flex-wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="id" value="<?= (int)$viewInstitute['id'] ?>">
      <input type="text" name="name" class="form-control" style="max-width:320px" value="<?= h($viewInstitute['name']) ?>" required>
      <button type="submit" class="btn btn-brand">Save name</button>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title">Add another admin for <?= h($viewInstitute['name']) ?></div>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_admin">
      <input type="hidden" name="institute_id" value="<?= (int)$viewInstitute['id'] ?>">
      <div class="col-md-3">
        <label class="form-label">Full name</label>
        <input type="text" name="full_name" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" minlength="6" required>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-brand w-100">Add admin</button>
      </div>
    </form>
  </div>

  <div class="panel mb-0">
    <div class="panel-title">Admins for this institute (<?= count($viewAdmins) ?>)</div>
    <?php if (!$viewAdmins): ?>
      <div class="empty-state"><i class="bi bi-person-badge"></i>No admin accounts yet.</div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Name</th><th>Username</th><th>Joined</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($viewAdmins as $a): ?>
              <tr>
                <td class="fw-semibold"><?= h($a['full_name']) ?></td>
                <td><?= h($a['username']) ?></td>
                <td class="text-muted small"><?= h($a['created_at']) ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#reset-<?= (int)$a['id'] ?>" title="Reset password"><i class="bi bi-key"></i></button>
                  <form method="post" onsubmit="return confirm('Remove this admin account?');" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="institute_id" value="<?= (int)$viewInstitute['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
              <tr class="collapse" id="reset-<?= (int)$a['id'] ?>">
                <td colspan="4">
                  <form method="post" class="d-flex gap-2 align-items-center py-2 flex-wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_admin_password">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="institute_id" value="<?= (int)$viewInstitute['id'] ?>">
                    <input type="text" name="password" class="form-control form-control-sm" style="max-width:220px" placeholder="New password (min 6 chars)" minlength="6" required>
                    <button type="submit" class="btn btn-sm btn-brand">Reset password</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-title">Create a new institute</div>
        <p class="text-muted small">This creates the institute and its first admin account in one step. That admin can then log in and add their own teachers, students, departments, and everything else — fully separate from every other institute.</p>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_institute">
          <div class="mb-3">
            <label class="form-label">Institute name</label>
            <input type="text" name="institute_name" class="form-control" placeholder="e.g. Kurdistan Institute" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Logo (optional)</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" class="form-control">
            <div class="form-text">PNG, JPG, GIF, or WEBP. Max 20 MB. You can add or change this later too.</div>
          </div>
          <hr>
          <div class="mb-3">
            <label class="form-label">Admin full name</label>
            <input type="text" name="admin_full_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Admin username</label>
            <input type="text" name="admin_username" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Admin password</label>
            <input type="password" name="admin_password" class="form-control" minlength="6" required>
          </div>
          <button type="submit" class="btn btn-brand w-100">Create institute</button>
        </form>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="panel mb-0">
        <div class="panel-title">All institutes (<?= count($institutes) ?>)</div>
        <?php if (!$institutes): ?>
          <div class="empty-state"><i class="bi bi-building"></i>No institutes yet — create your first one.</div>
        <?php else: ?>
          <div class="table-scroll">
            <table class="table">
              <thead><tr><th></th><th>Institute</th><th>Admins</th><th>Teachers</th><th>Students</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($institutes as $inst): ?>
                  <tr>
                    <td>
                      <?php if ($inst['logo_stored_filename']): ?>
                        <img src="<?= h(base_url('uploads/logos/' . $inst['logo_stored_filename'])) ?>" alt="" style="width:36px;height:36px;object-fit:contain;border-radius:6px;border:1px solid var(--ink-100);">
                      <?php else: ?>
                        <div class="text-muted"><i class="bi bi-building"></i></div>
                      <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= h($inst['name']) ?></td>
                    <td><?= (int)$inst['admin_count'] ?></td>
                    <td><?= (int)$inst['teacher_count'] ?></td>
                    <td><?= (int)$inst['student_count'] ?></td>
                    <td>
                      <?php if ($inst['is_active']): ?>
                        <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                      <?php else: ?>
                        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Disabled</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a href="?view=<?= (int)$inst['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> Manage</a>
                      <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="id" value="<?= (int)$inst['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Enable/disable this institute's logins"><i class="bi bi-toggle2-on"></i></button>
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

<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
