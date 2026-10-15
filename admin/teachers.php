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
        $gender = normalize_gender($_POST['gender'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $fullName === '' || strlen($password) < 6) {
            flash_set('danger', 'Username, full name, and a password of 6+ characters are required.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO teachers (institute_id, username, password_hash, full_name, gender, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssss', $instituteId, $username, $hash, $fullName, $gender, $email, $phone);
            try {
                $stmt->execute();
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Added teacher \"$fullName\"");
                flash_set('success', 'Teacher account created.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That username is already taken.');
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $gender = normalize_gender($_POST['gender'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $before = teacher_belongs_to_institute($conn, $id, $instituteId) ? get_teacher($conn, $id, $instituteId) : null;

        if (!$before) {
            flash_set('danger', 'Teacher not found.');
        } elseif ($username === '' || $fullName === '') {
            flash_set('danger', 'Username and full name are required.');
        } else {
            $stmt = $conn->prepare("UPDATE teachers SET username = ?, full_name = ?, gender = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param('sssssi', $username, $fullName, $gender, $email, $phone, $id);
            try {
                $stmt->execute();
                $after = ['username' => $username, 'full_name' => $fullName, 'gender' => $gender, 'email' => $email, 'phone' => $phone];
                $labels = ['full_name' => 'Name', 'username' => 'Username', 'gender' => 'Gender', 'email' => 'Email', 'phone' => 'Phone'];
                $changes = diff_summary($before, $after, $labels);
                $summary = $changes !== '' ? "Updated teacher \"{$before['full_name']}\" — $changes" : "Updated teacher \"{$before['full_name']}\" (no changes)";
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), $summary);
                flash_set('success', 'Teacher info updated.');
            } catch (mysqli_sql_exception $e) {
                flash_set('danger', 'That username is already taken by another account.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_teacher($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("UPDATE teachers SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $newStatus = $before['is_active'] ? 'Disabled' : 'Active';
            $oldStatus = $before['is_active'] ? 'Active' : 'Disabled';
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Changed status for teacher \"{$before['full_name']}\": $oldStatus → $newStatus");
            flash_set('success', 'Teacher status updated.');
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $before = get_teacher($conn, $id, $instituteId);
        if (!$before) {
            flash_set('danger', 'Teacher not found.');
        } elseif (strlen($password) < 6) {
            flash_set('danger', 'Password must be at least 6 characters.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE teachers SET password_hash = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Reset password for teacher \"{$before['full_name']}\"");
            flash_set('success', 'Password reset.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $before = get_teacher($conn, $id, $instituteId);
        if ($before) {
            $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Deleted teacher \"{$before['full_name']}\" (username: {$before['username']})");
            flash_set('success', 'Teacher deleted.');
        }
    } elseif ($action === 'assign') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classSectionId = (int)($_POST['class_section_id'] ?? 0);
        $subjectIds = array_map('intval', $_POST['subject_ids'] ?? []);

        $teacherRow = teacher_belongs_to_institute($conn, $teacherId, $instituteId) ? get_teacher($conn, $teacherId, $instituteId) : null;
        if (!$teacherRow
            || !class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) {
            flash_set('danger', 'Teacher or class section not found.');
        } elseif (!$subjectIds) {
            flash_set('danger', 'Select at least one subject to assign.');
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO teacher_assignments (teacher_id, class_section_id, subject_id) VALUES (?, ?, ?)");
            $added = 0;
            $mismatched = 0;
            $addedNames = [];
            foreach ($subjectIds as $subjectId) {
                if (!$subjectId || !subject_belongs_to_institute($conn, $subjectId, $instituteId)) continue;
                if (!subject_in_class_section($conn, $subjectId, $classSectionId)) {
                    $mismatched++; // e.g. an Accounting subject for a Computer class
                    continue;
                }
                $stmt->bind_param('iii', $teacherId, $classSectionId, $subjectId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $added++;
                    $subjRow = $conn->query("SELECT name FROM subjects WHERE id = " . (int)$subjectId)->fetch_assoc();
                    if ($subjRow) $addedNames[] = $subjRow['name'];
                }
            }
            $sectionRow = $conn->query(
                "SELECT cs.grade, cs.class_group, d.name AS department_name FROM class_sections cs JOIN departments d ON d.id = cs.department_id WHERE cs.id = " . (int)$classSectionId
            )->fetch_assoc();
            $sectionLabel = $sectionRow ? class_section_label($sectionRow) : "section #$classSectionId";
            if ($addedNames) {
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Assigned teacher \"{$teacherRow['full_name']}\" to $sectionLabel — subject(s): " . implode(', ', $addedNames));
            }
            if ($added === 0 && $mismatched > 0) {
                flash_set('danger', 'Those subjects belong to a different department than that class, so nothing was assigned.');
            } else {
                flash_set('success', $added > 0 ? "Assigned $added subject(s) for that class." : 'Those subjects were already assigned.');
            }
        }
        header('Location: ' . base_url('admin/teachers.php') . '?view=' . $teacherId);
        exit;
    } elseif ($action === 'unassign') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        if (teacher_belongs_to_institute($conn, $teacherId, $instituteId)) {
            $assignRow = $conn->query(
                "SELECT ta.id, t.full_name AS teacher_name, sub.name AS subject_name, cs.grade, cs.class_group, d.name AS department_name
                 FROM teacher_assignments ta
                 JOIN teachers t ON t.id = ta.teacher_id
                 JOIN subjects sub ON sub.id = ta.subject_id
                 JOIN class_sections cs ON cs.id = ta.class_section_id
                 JOIN departments d ON d.id = cs.department_id
                 WHERE ta.id = " . (int)$assignmentId . " AND ta.teacher_id = " . (int)$teacherId
            )->fetch_assoc();
            // Only remove the assignment if it actually belongs to this teacher (who we've confirmed is ours).
            $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE id = ? AND teacher_id = ?");
            $stmt->bind_param('ii', $assignmentId, $teacherId);
            $stmt->execute();
            if ($assignRow && $stmt->affected_rows > 0) {
                $sectionLabel = class_section_label($assignRow);
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Unassigned teacher \"{$assignRow['teacher_name']}\" from {$assignRow['subject_name']} in $sectionLabel");
            }
            flash_set('success', 'Assignment removed.');
        }
        header('Location: ' . base_url('admin/teachers.php') . '?view=' . $teacherId);
        exit;
    }
    header('Location: ' . base_url('admin/teachers.php'));
    exit;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE institute_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY full_name");
    $stmt->bind_param('isss', $instituteId, $like, $like, $like);
    $stmt->execute();
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE institute_id = ? ORDER BY full_name");
    $stmt->bind_param('i', $instituteId);
    $stmt->execute();
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$sections = all_class_sections($conn, $instituteId);
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;
$viewTeacher = null;
$assignments = [];
$subjectsByDept = [];

if ($viewId && teacher_belongs_to_institute($conn, $viewId, $instituteId)) {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $viewTeacher = $stmt->get_result()->fetch_assoc();
    if ($viewTeacher) {
        $assignments = teacher_assignments($conn, $viewId);
        foreach (all_departments($conn, $instituteId) as $d) {
            $subjectsByDept[$d['id']] = subjects_for_department($conn, $d['id'], $instituteId);
        }
    }
}

$pageTitle = 'Teachers';
$role = 'admin';
$activePage = 'teachers';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>

<?php if ($viewTeacher): ?>
  <a href="<?= base_url('admin/teachers.php') ?>" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back to all teachers</a>

  <div class="panel">
    <div class="panel-title">Class assignments for <?= h($viewTeacher['full_name']) ?></div>
    <p class="text-muted small">A teacher can only enter marks for the class section + subject combinations assigned here.</p>

    <?php if (!$sections): ?>
      <div class="alert alert-warning py-2">Add class sections and subjects first.</div>
    <?php else: ?>
      <form method="post" class="row g-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="teacher_id" value="<?= (int)$viewTeacher['id'] ?>">
        <div class="col-md-6">
          <label class="form-label">Class section</label>
          <select name="class_section_id" id="section_select" class="form-select" required>
            <option value="">Select...</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s['id'] ?>" data-department="<?= (int)$s['department_id'] ?>"><?= h(class_section_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Subjects — pick as many as you need for this class in one go</label>
          <div id="subject_checkboxes" class="d-flex flex-wrap gap-2 p-3" style="border:1px solid var(--ink-100); border-radius:10px; min-height:52px;">
            <span class="text-muted small">Select a class section first.</span>
          </div>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-brand"><i class="bi bi-plus-lg me-1"></i>Assign selected subjects</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if (!$assignments): ?>
      <div class="empty-state"><i class="bi bi-diagram-3"></i>No assignments yet.</div>
    <?php else: ?>
      <div class="table-scroll">
      <table class="table">
        <thead><tr><th>Department</th><th>Grade</th><th>Group</th><th>Subject</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($assignments as $a): ?>
            <tr>
              <td><?= h($a['department_name']) ?></td>
              <td><?= h($a['grade']) ?></td>
              <td><?= h($a['class_group']) ?></td>
              <td class="fw-semibold"><?= h($a['subject_name']) ?></td>
              <td class="text-end">
                <form method="post" onsubmit="return confirm('Remove this assignment?');" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unassign">
                  <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                  <input type="hidden" name="teacher_id" value="<?= (int)$viewTeacher['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <script>
    var subjectsByDept = <?= json_encode($subjectsByDept) ?>;
    var sectionSelect = document.getElementById('section_select');
    var checkboxWrap = document.getElementById('subject_checkboxes');
    if (sectionSelect) {
      sectionSelect.addEventListener('change', function () {
        var opt = sectionSelect.options[sectionSelect.selectedIndex];
        var deptId = opt.getAttribute('data-department');
        var subs = subjectsByDept[deptId] || [];
        checkboxWrap.innerHTML = '';
        if (!subs.length) {
          checkboxWrap.innerHTML = '<span class="text-muted small">No subjects exist for this department yet.</span>';
          return;
        }
        subs.forEach(function (s) {
          var label = document.createElement('label');
          label.className = 'btn btn-sm btn-outline-secondary';
          label.style.borderRadius = '999px';
          var input = document.createElement('input');
          input.type = 'checkbox';
          input.name = 'subject_ids[]';
          input.value = s.id;
          input.className = 'btn-check';
          input.id = 'subj_' + s.id;
          label.setAttribute('for', 'subj_' + s.id);
          label.textContent = s.name;
          checkboxWrap.appendChild(input);
          checkboxWrap.appendChild(label);
        });
      });
    }
  </script>

<?php else: ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="panel">
        <div class="panel-title">Add teacher</div>
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
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="">Not set</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control">
          </div>
          <button type="submit" class="btn btn-brand w-100">Create teacher</button>
        </form>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="panel-title mb-0">All teachers (<?= count($teachers) ?>)</div>
          <form method="get" class="d-flex gap-2">
            <input type="text" name="q" class="form-control form-control-sm" style="min-width:200px" placeholder="Search by name, username, email..." value="<?= h($search) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?>
              <a href="<?= base_url('admin/teachers.php') ?>" class="btn btn-sm btn-outline-secondary" title="Clear search"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
            <a href="<?= base_url('admin/teachers_export.php') ?><?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-sm btn-outline-secondary" title="Export to Excel"><i class="bi bi-file-earmark-excel me-1"></i>Export</a>
          </form>
        </div>
        <?php if (!$teachers): ?>
          <div class="empty-state"><i class="bi bi-person-workspace"></i><?= $search !== '' ? 'No teachers match your search.' : 'No teachers yet.' ?></div>
        <?php else: ?>
          <div class="table-scroll">
          <table class="table">
            <thead><tr><th>Name</th><th>Gender</th><th>Username</th><th>Contact</th><th>Joined</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($teachers as $t): ?>
                <tr>
                  <td class="fw-semibold"><?= h($t['full_name']) ?></td>
                  <td class="text-muted small"><?= gender_label($t['gender']) ?></td>
                  <td><?= h($t['username']) ?></td>
                  <td class="text-muted small"><?= h($t['email']) ?><?php if ($t['phone']): ?><br><?= h($t['phone']) ?><?php endif; ?></td>
                  <td class="text-muted small"><?= h($t['created_at']) ?></td>
                  <td>
                    <?php if ($t['is_active']): ?>
                      <span class="badge text-bg-success-subtle text-success-emphasis">Active</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary-subtle text-secondary-emphasis">Disabled</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end text-nowrap">
                    <a href="?view=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Manage assignments"><i class="bi bi-diagram-3"></i></a>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int)$t['id'] ?>" title="Edit info"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#reset-<?= (int)$t['id'] ?>" title="Reset password"><i class="bi bi-key"></i></button>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-warning" title="Enable/disable"><i class="bi bi-toggle2-on"></i></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this teacher permanently?');" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <tr class="collapse" id="edit-<?= (int)$t['id'] ?>">
                  <td colspan="7">
                    <form method="post" class="row g-2 align-items-end py-2">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <div class="col-sm-3">
                        <label class="form-label small mb-1">Full name</label>
                        <input type="text" name="full_name" class="form-control form-control-sm" value="<?= h($t['full_name']) ?>" required>
                      </div>
                      <div class="col-sm-2">
                        <label class="form-label small mb-1">Username</label>
                        <input type="text" name="username" class="form-control form-control-sm" value="<?= h($t['username']) ?>" required>
                      </div>
                      <div class="col-sm-2">
                        <label class="form-label small mb-1">Gender</label>
                        <select name="gender" class="form-select form-select-sm">
                          <option value="" <?= !$t['gender'] ? 'selected' : '' ?>>Not set</option>
                          <option value="male" <?= $t['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                          <option value="female" <?= $t['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        </select>
                      </div>
                      <div class="col-sm-3">
                        <label class="form-label small mb-1">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= h($t['email']) ?>">
                      </div>
                      <div class="col-sm-2">
                        <label class="form-label small mb-1">Phone</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($t['phone']) ?>">
                      </div>
                      <div class="col-sm-2">
                        <button type="submit" class="btn btn-sm btn-brand w-100">Save changes</button>
                      </div>
                    </form>
                  </td>
                </tr>
                <tr class="collapse" id="reset-<?= (int)$t['id'] ?>">
                  <td colspan="7">
                    <form method="post" class="d-flex gap-2 align-items-center py-2 flex-wrap">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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

<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
