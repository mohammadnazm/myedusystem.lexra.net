<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$adminId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash FROM admins WHERE id = ?");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        flash_set('danger', 'Your current password is incorrect.');
    } elseif (strlen($new) < 6) {
        flash_set('danger', 'New password must be at least 6 characters.');
    } elseif ($new !== $confirm) {
        flash_set('danger', 'New password and confirmation do not match.');
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
        $update->bind_param('si', $hash, $adminId);
        $update->execute();
        log_activity($conn, current_institute_id(), 'admin', $adminId, current_user_name(), 'Changed their own password');
        flash_set('success', 'Password updated.');
    }
    header('Location: ' . base_url('admin/profile.php'));
    exit;
}

$pageTitle = 'My Profile';
$role = 'admin';
$activePage = 'profile';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-title">Change password</div>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Current password</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">New password (min 6 characters)</label>
          <input type="password" name="new_password" class="form-control" minlength="6" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm new password</label>
          <input type="password" name="confirm_password" class="form-control" minlength="6" required>
        </div>
        <button type="submit" class="btn btn-brand">Update password</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
