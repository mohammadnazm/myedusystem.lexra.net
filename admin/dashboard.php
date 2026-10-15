<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

$counts = [
    'departments' => scalar_count($conn, "SELECT COUNT(*) c FROM departments WHERE institute_id = ?", $instituteId),
    'sections'    => scalar_count($conn, "SELECT COUNT(*) c FROM class_sections cs JOIN departments d ON d.id = cs.department_id WHERE d.institute_id = ?", $instituteId),
    'teachers'    => scalar_count($conn, "SELECT COUNT(*) c FROM teachers WHERE institute_id = ?", $instituteId),
    'students'    => scalar_count($conn, "SELECT COUNT(*) c FROM students WHERE institute_id = ? AND is_active = 1", $instituteId),
    'disabled_students' => scalar_count($conn, "SELECT COUNT(*) c FROM students WHERE institute_id = ? AND is_active = 0", $instituteId),
];

$teacherGender = gender_breakdown($conn, 'teachers', $instituteId);
$studentGender = gender_breakdown($conn, 'students', $instituteId);
$deptBreakdown = students_by_department($conn, $instituteId);
$gradeBreakdown = students_by_grade($conn, $instituteId);

$pageTitle = 'Dashboard';
$role = 'admin';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-diagram-3"></i></div>
      <div>
        <div class="stat-value"><?= (int)$counts['departments'] ?></div>
        <div class="stat-label">Departments</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-grid-3x3-gap"></i></div>
      <div>
        <div class="stat-value"><?= (int)$counts['sections'] ?></div>
        <div class="stat-label">Class Sections</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-person-workspace"></i></div>
      <div>
        <div class="stat-value"><?= (int)$counts['teachers'] ?></div>
        <div class="stat-label">Teachers</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-people"></i></div>
      <div>
        <div class="stat-value"><?= (int)$counts['students'] ?></div>
        <div class="stat-label">Active Students</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <a href="<?= base_url('admin/students.php') ?>?status=disabled" class="stat-card text-decoration-none text-reset">
      <div class="icon-wrap"><i class="bi bi-person-dash"></i></div>
      <div>
        <div class="stat-value"><?= (int)$counts['disabled_students'] ?></div>
        <div class="stat-label">Disabled Students</div>
      </div>
    </a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="panel mb-0">
      <div class="panel-title">Teachers by gender</div>
      <div class="d-flex gap-4 flex-wrap">
        <div>
          <div class="stat-value"><i class="bi bi-gender-male me-1 text-primary"></i><?= (int)$teacherGender['male'] ?></div>
          <div class="stat-label">Male</div>
        </div>
        <div>
          <div class="stat-value"><i class="bi bi-gender-female me-1 text-danger"></i><?= (int)$teacherGender['female'] ?></div>
          <div class="stat-label">Female</div>
        </div>
        <?php if ($teacherGender['unspecified']): ?>
          <div>
            <div class="stat-value text-muted"><?= (int)$teacherGender['unspecified'] ?></div>
            <div class="stat-label">Not set</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="panel mb-0">
      <div class="panel-title">Active students by gender</div>
      <div class="d-flex gap-4 flex-wrap">
        <div>
          <div class="stat-value"><i class="bi bi-gender-male me-1 text-primary"></i><?= (int)$studentGender['male'] ?></div>
          <div class="stat-label">Male</div>
        </div>
        <div>
          <div class="stat-value"><i class="bi bi-gender-female me-1 text-danger"></i><?= (int)$studentGender['female'] ?></div>
          <div class="stat-label">Female</div>
        </div>
        <?php if ($studentGender['unspecified']): ?>
          <div>
            <div class="stat-value text-muted"><?= (int)$studentGender['unspecified'] ?></div>
            <div class="stat-label">Not set</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="panel mb-0 h-100">
      <div class="panel-title">Students by department</div>
      <?php if (!$deptBreakdown): ?>
        <div class="empty-state py-3"><i class="bi bi-diagram-3"></i>No active students yet.</div>
      <?php else: ?>
        <div class="table-scroll table-scroll-sm">
          <table class="table">
            <thead><tr><th>Department</th><th class="text-end">Male</th><th class="text-end">Female</th><?php if (array_sum(array_column($deptBreakdown, 'unspecified'))): ?><th class="text-end">Not set</th><?php endif; ?><th class="text-end">Total</th></tr></thead>
            <tbody>
              <?php $unspecifiedColShown = array_sum(array_column($deptBreakdown, 'unspecified')) > 0; ?>
              <?php foreach ($deptBreakdown as $row): ?>
                <tr>
                  <td class="fw-semibold"><?= h($row['department_name']) ?></td>
                  <td class="text-end"><i class="bi bi-gender-male me-1 text-primary"></i><?= (int)$row['male'] ?></td>
                  <td class="text-end"><i class="bi bi-gender-female me-1 text-danger"></i><?= (int)$row['female'] ?></td>
                  <?php if ($unspecifiedColShown): ?><td class="text-end text-muted"><?= (int)$row['unspecified'] ?></td><?php endif; ?>
                  <td class="text-end fw-semibold"><?= (int)$row['total'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel mb-0 h-100">
      <div class="panel-title">Students by grade</div>
      <?php if (!$gradeBreakdown): ?>
        <div class="empty-state py-3"><i class="bi bi-grid-3x3-gap"></i>No active students yet.</div>
      <?php else: ?>
        <div class="table-scroll table-scroll-sm">
          <table class="table">
            <thead><tr><th>Department</th><th>Grade</th><th class="text-end">Male</th><th class="text-end">Female</th><?php if (array_sum(array_column($gradeBreakdown, 'unspecified'))): ?><th class="text-end">Not set</th><?php endif; ?><th class="text-end">Total</th></tr></thead>
            <tbody>
              <?php $unspecifiedGradeColShown = array_sum(array_column($gradeBreakdown, 'unspecified')) > 0; ?>
              <?php foreach ($gradeBreakdown as $row): ?>
                <tr>
                  <td class="text-muted small"><?= h($row['department_name']) ?></td>
                  <td class="fw-semibold">Grade <?= h($row['grade']) ?></td>
                  <td class="text-end"><i class="bi bi-gender-male me-1 text-primary"></i><?= (int)$row['male'] ?></td>
                  <td class="text-end"><i class="bi bi-gender-female me-1 text-danger"></i><?= (int)$row['female'] ?></td>
                  <?php if ($unspecifiedGradeColShown): ?><td class="text-end text-muted"><?= (int)$row['unspecified'] ?></td><?php endif; ?>
                  <td class="text-end fw-semibold"><?= (int)$row['total'] ?></td>
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
