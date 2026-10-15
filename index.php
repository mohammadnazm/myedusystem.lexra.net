<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

// Already logged in? send them to their dashboard.
if (current_role()) {
    $target = match (current_role()) {
        'owner' => 'owner/dashboard.php',
        'admin' => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'accountant' => 'accountant/dashboard.php',
        'student' => 'student/profile.php',
        default => 'index.php',
    };
    header('Location: ' . base_url($target));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Please enter both fields.';
    } elseif (($lock = login_throttle_status($conn, $identifier))['blocked']) {
        // Don't even test the password while locked — that's the whole point of the lock.
        $error = 'Too many failed sign-in attempts. Please wait ' . $lock['minutes'] . ' minute' . ($lock['minutes'] === 1 ? '' : 's') . ' and try again.';
    } else {
        // One login form for everyone — try each account type in turn.
        // Whoever's username (or student ID) and password match wins;
        // nobody has to know or choose which kind of account they have.

        // 1. Platform owner
        $stmt = $conn->prepare("SELECT * FROM super_admins WHERE username = ?");
        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc();

        if ($owner && password_verify($password, $owner['password_hash'])) {
            login_clear_failures($conn, $identifier);
            login_as('owner', (int)$owner['id'], $owner['full_name']);
            header('Location: ' . base_url('owner/dashboard.php'));
            exit;
        }

        // 2. Institute admin
        $stmt = $conn->prepare(
            "SELECT a.*, i.name AS institute_name, i.is_active AS institute_active
             FROM admins a
             JOIN institutes i ON i.id = a.institute_id
             WHERE a.username = ?"
        );
        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            if (!(int)$admin['institute_active']) {
                $error = 'This institute account is currently inactive. Contact the platform owner.';
            } else {
                login_clear_failures($conn, $identifier);
                login_as('admin', (int)$admin['id'], $admin['full_name'], (int)$admin['institute_id'], $admin['institute_name']);
                header('Location: ' . base_url('admin/dashboard.php'));
                exit;
            }
        }

        // 3. Teacher
        if (!$error) {
            $stmt = $conn->prepare(
                "SELECT t.*, i.name AS institute_name, i.is_active AS institute_active
                 FROM teachers t
                 JOIN institutes i ON i.id = t.institute_id
                 WHERE t.username = ? AND t.is_active = 1"
            );
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $teacher = $stmt->get_result()->fetch_assoc();

            if ($teacher && password_verify($password, $teacher['password_hash'])) {
                if (!(int)$teacher['institute_active']) {
                    $error = 'This institute account is currently inactive. Contact the platform owner.';
                } else {
                login_clear_failures($conn, $identifier);
                login_as('teacher', (int)$teacher['id'], $teacher['full_name'], (int)$teacher['institute_id'], $teacher['institute_name']);
                    header('Location: ' . base_url('teacher/dashboard.php'));
                    exit;
                }
            }
        }

        // 4. Accountant
        if (!$error) {
            $stmt = $conn->prepare(
                "SELECT ac.*, i.name AS institute_name, i.is_active AS institute_active
                 FROM accountants ac
                 JOIN institutes i ON i.id = ac.institute_id
                 WHERE ac.username = ? AND ac.is_active = 1"
            );
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $accountant = $stmt->get_result()->fetch_assoc();

            if ($accountant && password_verify($password, $accountant['password_hash'])) {
                if (!(int)$accountant['institute_active']) {
                    $error = 'This institute account is currently inactive. Contact the platform owner.';
                } else {
                login_clear_failures($conn, $identifier);
                login_as('accountant', (int)$accountant['id'], $accountant['full_name'], (int)$accountant['institute_id'], $accountant['institute_name']);
                    header('Location: ' . base_url('accountant/dashboard.php'));
                    exit;
                }
            }
        }

        // 5. Student — logs in with their numeric ID, so only try this
        //    when what was typed is actually all digits.
        if (!$error && ctype_digit($identifier)) {
            $stmt = $conn->prepare(
                "SELECT s.*, i.name AS institute_name, i.is_active AS institute_active
                 FROM students s
                 JOIN institutes i ON i.id = s.institute_id
                 WHERE s.id = ? AND s.is_active = 1"
            );
            $id = (int)$identifier;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();

            if ($student && password_verify($password, $student['password_hash'])) {
                if (!(int)$student['institute_active']) {
                    $error = 'This institute account is currently inactive. Contact the platform owner.';
                } else {
                login_clear_failures($conn, $identifier);
                login_as('student', (int)$student['id'], student_full_name($student), (int)$student['institute_id'], $student['institute_name']);
                    header('Location: ' . base_url('student/profile.php'));
                    exit;
                }
            }
        }

        if (!$error) {
            $error = 'Invalid username/ID or password.';
            login_record_failure($conn, $identifier);
        }
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-screen">
  <div class="auth-card">
    <?= i18n_switcher('auth') ?>
    <div class="auth-visual">
      <i class="bi bi-mortarboard-fill"></i>
      <h2>Institute Management System</h2>
      <p>One place to manage departments, classes, teachers, students and marks.</p>
    </div>
    <div class="auth-form-wrap">
      <h3>Welcome back</h3>
      <p class="subtitle">Sign in to continue</p>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
          <label class="form-label">Username or Student ID</label>
          <input type="text" name="identifier" id="login-identifier" class="form-control" required autofocus value="<?= h($_POST['identifier'] ?? '') ?>" oninput="toggleLoginPasswordLabel()">
        </div>
        <div class="mb-2">
          <label class="form-label" id="login-password-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <p class="form-text mt-0 mb-3">Students sign in with their login ID above and their National ID Number as the password.</p>
        <button type="submit" class="btn btn-brand w-100">Sign in</button>
      </form>
    </div>
  </div>
</div>
<script>
function toggleLoginPasswordLabel() {
  var identifier = document.getElementById('login-identifier').value.trim();
  var label = document.getElementById('login-password-label');
  label.textContent = /^\d+$/.test(identifier) ? <?= json_encode(t('National ID Number'), JSON_UNESCAPED_UNICODE) ?> : <?= json_encode(t('Password'), JSON_UNESCAPED_UNICODE) ?>;
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
