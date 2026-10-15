<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $studentIds = array_map('intval', $_POST['student_ids'] ?? []);
    $fromForStudent = $_POST['from_for_student'] ?? [];
    $targetForStudent = $_POST['target_for_student'] ?? [];

    if (!$studentIds) {
        flash_set('danger', 'No students were selected to promote.');
    } else {
        // Group the selected students by (source section, target section) —
        // a single submission can span several groups within the same grade.
        $groups = [];
        foreach ($studentIds as $id) {
            $from = (int)($fromForStudent[$id] ?? 0);
            $to = (int)($targetForStudent[$id] ?? 0);
            if (!$from || !$to) {
                continue;
            }
            // Tamper-safety: both ends of the move must actually belong to this institute.
            if (!class_section_belongs_to_institute($conn, $from, $instituteId) || !class_section_belongs_to_institute($conn, $to, $instituteId)) {
                continue;
            }
            $key = $from . '-' . $to;
            $groups[$key]['from'] = $from;
            $groups[$key]['to'] = $to;
            $groups[$key]['ids'][] = $id;
        }

        $totalMoved = 0;
        $moveDescriptions = [];
        foreach ($groups as $g) {
            $moved = promote_students($conn, $g['ids'], $g['from'], $g['to'], $instituteId);
            if ($moved > 0) {
                $fromRow = $conn->query("SELECT cs.grade, cs.class_group, d.name AS department_name FROM class_sections cs JOIN departments d ON d.id = cs.department_id WHERE cs.id = " . (int)$g['from'])->fetch_assoc();
                $toRow = $conn->query("SELECT cs.grade, cs.class_group, d.name AS department_name FROM class_sections cs JOIN departments d ON d.id = cs.department_id WHERE cs.id = " . (int)$g['to'])->fetch_assoc();
                if ($fromRow && $toRow) {
                    $moveDescriptions[] = "$moved from " . class_section_label($fromRow) . ' → ' . class_section_label($toRow);
                }
            }
            $totalMoved += $moved;
        }

        if ($totalMoved > 0) {
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Promoted $totalMoved student(s): " . implode('; ', $moveDescriptions));
            flash_set('success', "$totalMoved student(s) promoted.");
        } else {
            flash_set('danger', 'Nothing was moved — the selection may be out of date, try again.');
        }
    }
    header('Location: ' . base_url('admin/promote.php'));
    exit;
}

$departments = all_departments($conn, $instituteId);
$departmentId = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$fromGrade = $_GET['from_grade'] ?? '';
$toGrade = $_GET['to_grade'] ?? '';

$grades = $departmentId ? grades_for_department($conn, $departmentId, $instituteId) : [];
$toGradeOptions = array_values(array_filter($grades, fn($g) => $g !== $fromGrade));

$mapping = [];
if ($departmentId && $fromGrade !== '' && $toGrade !== '') {
    $mapping = build_promotion_mapping($conn, $departmentId, $fromGrade, $toGrade, $instituteId);
}

$pageTitle = 'Promote Students';
$role = 'admin';
$activePage = 'promote';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Promote a whole grade at once</div>
  <p class="text-muted small mb-3">
    Pick a department, the grade students are currently in, and the grade to move them to.
    Groups are matched automatically by their label (e.g. "9-A" → "10-A") — if a matching
    group doesn't exist yet in the target grade, create it first on the
    <a href="<?= base_url('admin/sections.php') ?>">Class Sections</a> page, then come back here.
    You can deselect anyone who shouldn't move (e.g. repeating the grade) before confirming.
  </p>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label">Department</label>
      <select name="department" class="form-select" onchange="this.form.submit()" required>
        <option value="">Select...</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $departmentId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">From grade (current)</label>
      <select name="from_grade" class="form-select" onchange="this.form.submit()" <?= $departmentId ? '' : 'disabled' ?>>
        <option value="">Select...</option>
        <?php foreach ($grades as $g): ?>
          <option value="<?= h($g) ?>" <?= $fromGrade === $g ? 'selected' : '' ?>>Grade <?= h($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">To grade (new)</label>
      <select name="to_grade" class="form-select" onchange="this.form.submit()" <?= $fromGrade ? '' : 'disabled' ?>>
        <option value="">Select...</option>
        <?php foreach ($toGradeOptions as $g): ?>
          <option value="<?= h($g) ?>" <?= $toGrade === $g ? 'selected' : '' ?>>Grade <?= h($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if ($mapping): ?>
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($mapping as $row):
      $from = $row['from_section'];
      $to = $row['to_section'];
      $students = students_in_section($conn, (int)$from['id']);
    ?>
      <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <div class="panel-title mb-0">
            Group <?= h($from['class_group']) ?>
            <i class="bi bi-arrow-right mx-2"></i>
            <?= $to ? 'Grade ' . h($toGrade) . ' — Group ' . h($to['class_group']) : '<span class="text-danger">No matching group in Grade ' . h($toGrade) . '</span>' ?>
          </div>
          <?php if ($to): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleGroup('<?= (int)$from['id'] ?>', true)">Select all</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleGroup('<?= (int)$from['id'] ?>', false)">Select none</button>
          <?php endif; ?>
        </div>

        <?php if (!$to): ?>
          <div class="empty-state py-3">
            <i class="bi bi-exclamation-triangle"></i>
            Create a "<?= h($from['class_group']) ?>" section in Grade <?= h($toGrade) ?> on the
            <a href="<?= base_url('admin/sections.php') ?>">Class Sections</a> page to enable this move.
          </div>
        <?php elseif (!$students): ?>
          <div class="empty-state py-3"><i class="bi bi-people"></i>No students currently in this group.</div>
        <?php else: ?>
          <div class="table-scroll">
            <table class="table" data-group="<?= (int)$from['id'] ?>">
              <thead><tr><th style="width:40px;"></th><th>Student</th></tr></thead>
              <tbody>
                <?php foreach ($students as $st): ?>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input" name="student_ids[]" value="<?= (int)$st['id'] ?>" checked>
                      <input type="hidden" name="from_for_student[<?= (int)$st['id'] ?>]" value="<?= (int)$from['id'] ?>">
                      <input type="hidden" name="target_for_student[<?= (int)$st['id'] ?>]" value="<?= (int)$to['id'] ?>">
                    </td>
                    <td><?= h(student_full_name($st)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="panel mb-0">
      <button type="submit" class="btn btn-brand"><i class="bi bi-arrow-up-right-circle me-1"></i>Promote selected students</button>
    </div>
  </form>

  <script>
    function toggleGroup(groupId, checked) {
      document.querySelectorAll('table[data-group="' + groupId + '"] input[type=checkbox]').forEach(function (cb) {
        cb.checked = checked;
      });
    }
  </script>
<?php elseif ($departmentId && $fromGrade && $toGrade): ?>
  <div class="panel"><div class="empty-state"><i class="bi bi-people"></i>No groups found in that grade.</div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
