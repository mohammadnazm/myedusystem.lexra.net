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
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            flash_set('danger', 'Username, full name, and a password of 6+ characters are required.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO accountants (institute_id, username, password_hash, full_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $instituteId, $username, $hash, $fullName, $email, $phone);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added accountant \"$fullName\"");
                flash_set('success', 'Accountant account created.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That username is already taken.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $before = accountant_belongs_to_institute($conn, $id, $instituteId) ? get_accountant($conn, $id, $instituteId) : null;

        if (!$before) {
            flash_set('danger', 'Accountant not found.');
        } elseif ($username === '' || $fullName === '') {
            flash_set('danger', 'Username and full name are required.');
        } else {
            $stmt = $conn->prepare("UPDATE accountants SET username = ?, full_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param('ssssi', $username, $fullName, $email, $phone, $id);
            try {
                $stmt->execute();
                $after = ['username' => $username, 'full_name' => $fullName, 'email' => $email, 'phone' => $phone];
                $labels = ['full_name' => 'Name', 'username' => 'Username', 'email' => 'Email', 'phone' => 'Phone'];
                $changes = diff_summary($before, $after, $labels);
                $summary = $changes !== '' ? "Updated accountant \"{$before['full_name']}\" — $changes" : "Updated accountant \"{$before['full_name']}\" (no changes)";
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), $summary);
                flash_set('success', 'Accountant info updated.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That username is already taken by another account.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_accountant($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("UPDATE accountants SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $newStatus = $before['is_active'] ? 'Disabled' : 'Active';
            $oldStatus = $before['is_active'] ? 'Active' : 'Disabled';
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Changed status for accountant \"{$before['full_name']}\": $oldStatus → $newStatus");
            flash_set('success', 'Accountant status updated.');
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $before = get_accountant($conn, $id, $instituteId);
        if (!$before) {
            flash_set('danger', 'Accountant not found.');
        } elseif (strlen($password) < 6) {
            flash_set('danger', 'Password must be at least 6 characters.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE accountants SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Reset password for accountant \"{$before['full_name']}\"");
            flash_set('success', 'Password reset.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_accountant($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("DELETE FROM accountants WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted accountant \"{$before['full_name']}\" (username: {$before['username']})");
            flash_set('success', 'Accountant deleted.');
        }
    }
    header('Location: ' . base_url('admin/accountants.php'));
    exit;
}

$search = trim($_GET['q'] ?? '');
$accountants = all_accountants($conn, $instituteId, $search);

$pageTitle = 'Accountants';
$role = 'admin';
$activePage = 'accountants';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-4 content-fill">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-title">Create accountant account</div>
      <p class="text-muted small mb-3">
        Accountants can sign in like any other account, but they only ever see student pricing and discounts —
        never marks, attendance, reports, or resources.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="mb-3">
          <label class="form-label">Full name</label>
          <input type="text" name="full_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" minlength="6" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control">
        </div>
        <button type="submit" class="btn btn-brand w-100">Create accountant</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="panel-title mb-0">All accountants (<?= count($accountants) ?>)</div>
        <form method="get" class="d-flex gap-2">
          <input type="text" name="q" class="form-control form-control-sm" style="min-width:200px" placeholder="Search by name, username, email..." value="<?= h($search) ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
          <?php if ($search !== ''): ?>
            <a href="<?= base_url('admin/accountants.php') ?>" class="btn btn-sm btn-outline-secondary" title="Clear search"><i class="bi bi-x-lg"></i></a>
          <?php endif; ?>
        </form>
      </div>
      <?php if (!$accountants): ?>
        <div class="empty-state"><i class="bi bi-person-badge"></i><?= $search !== '' ? 'No accountants match your search.' : 'No accountant accounts yet.' ?></div>
      <?php else: ?>
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Name</th><th>Username</th><th>Contact</th><th>Joined</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($accountants as $a): ?>
              <tr>
                <td class="fw-semibold"><?= h($a['full_name']) ?></td>
                <td><?= h($a['username']) ?></td>
                <td class="text-muted small"><?= h($a['email']) ?><?php if ($a['phone']): ?><br><?= h($a['phone']) ?><?php endif; ?></td>
                <td class="text-muted small"><?= h($a['created_at']) ?></td>
                <td>
                  <?php if ($a['is_active']): ?>
                    <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Disabled</span>
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int)$a['id'] ?>" title="Edit info"><i class="bi bi-pencil"></i></button>
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#reset-<?= (int)$a['id'] ?>" title="Reset password"><i class="bi bi-key"></i></button>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Enable/disable"><i class="bi bi-toggle2-on"></i></button>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete this accountant permanently?');" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
              <tr class="collapse" id="edit-<?= (int)$a['id'] ?>">
                <td colspan="6">
                  <form method="post" class="row g-2 align-items-end py-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <div class="col-sm-3">
                      <label class="form-label small mb-1">Full name</label>
                      <input type="text" name="full_name" class="form-control form-control-sm" value="<?= h($a['full_name']) ?>" required>
                    </div>
                    <div class="col-sm-2">
                      <label class="form-label small mb-1">Username</label>
                      <input type="text" name="username" class="form-control form-control-sm" value="<?= h($a['username']) ?>" required>
                    </div>
                    <div class="col-sm-3">
                      <label class="form-label small mb-1">Email</label>
                      <input type="email" name="email" class="form-control form-control-sm" value="<?= h($a['email']) ?>">
                    </div>
                    <div class="col-sm-2">
                      <label class="form-label small mb-1">Phone</label>
                      <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($a['phone']) ?>">
                    </div>
                    <div class="col-sm-2">
                      <button type="submit" class="btn btn-sm btn-brand w-100">Save changes</button>
                    </div>
                  </form>
                </td>
              </tr>
              <tr class="collapse" id="reset-<?= (int)$a['id'] ?>">
                <td colspan="6">
                  <form method="post" class="d-flex gap-2 align-items-center py-2 flex-wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
