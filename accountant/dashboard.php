<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('accountant');

$instituteId = current_institute_id();

$departments = all_departments($conn, $instituteId);
$filterDept = (int)($_GET['department_id'] ?? 0);
$filterGrade = trim($_GET['grade'] ?? '');
$search = trim($_GET['q'] ?? '');
$gradesForFilter = $filterDept ? grades_for_department($conn, $filterDept, $instituteId) : [];
$studentRows = students_with_pricing($conn, $instituteId, $filterDept ?: null, $filterGrade ?: null, $search);
$summary = accounting_summary($conn, $instituteId);

$pageTitle = 'Student Prices';
$role = 'accountant';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-people"></i></div>
      <div>
        <div class="stat-value"><?= (int)$summary['students'] ?></div>
        <div class="stat-label">Priced students</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-cash-stack"></i></div>
      <div>
        <div class="stat-value"><?= format_money($summary['total_base']) ?></div>
        <div class="stat-label">Total base fees</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-percent"></i></div>
      <div>
        <div class="stat-value"><?= format_money($summary['total_discount']) ?></div>
        <div class="stat-label">Total discounts given</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-wallet2"></i></div>
      <div>
        <div class="stat-value"><?= format_money($summary['total_final']) ?></div>
        <div class="stat-label">Expected revenue</div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 p-3 rounded-3" style="background:var(--brand-50);">
  <div><i class="bi bi-receipt me-2 text-primary"></i><strong>Student payments</strong> <span class="text-muted">— record payments, print receipts, and see what each student still owes.</span></div>
  <a href="<?= base_url('accountant/payments.php') ?>" class="btn btn-sm btn-brand">Open Payments <i class="bi bi-arrow-right ms-1"></i></a>
</div>

<div class="panel">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="panel-title mb-0">Student fees</div>
    <a href="<?= base_url('accountant/discounts_export.php') ?>?<?= http_build_query(['department_id' => $filterDept ?: null, 'grade' => $filterGrade ?: null, 'q' => $search ?: null]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Export discounted students to Excel</a>
  </div>
  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-sm-4 col-md-3">
      <label class="form-label small mb-1">Department</label>
      <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $filterDept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filterDept): ?>
      <div class="col-sm-3 col-md-2">
        <label class="form-label small mb-1">Grade</label>
        <select name="grade" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All grades</option>
          <?php foreach ($gradesForFilter as $g): ?>
            <option value="<?= h($g) ?>" <?= $filterGrade === $g ? 'selected' : '' ?>>Grade <?= h($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-sm-5 col-md-4">
      <label class="form-label small mb-1">Search student</label>
      <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Student name...">
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
      <?php if ($filterDept || $filterGrade !== '' || $search !== ''): ?>
        <a href="<?= base_url('accountant/dashboard.php') ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if (!$studentRows): ?>
    <div class="empty-state"><i class="bi bi-people"></i>No students match that filter.</div>
  <?php else: ?>
    <div class="table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th>Student</th><th>Department</th><th>Grade / Group</th>
          <th class="text-end">Base price</th><th>Discount</th>
          <th class="text-end">Final price</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($studentRows as $s): ?>
          <tr>
            <td class="fw-semibold"><?= h(student_full_name($s)) ?></td>
            <td class="text-muted small"><?= h($s['department_name']) ?></td>
            <td class="text-muted small">Grade <?= h($s['grade']) ?> / <?= h($s['class_group']) ?></td>
            <td class="text-end"><?= format_money($s['base_price']) ?><?php if ($s['base_price'] <= 0): ?> <span class="badge text-bg-warning-subtle text-warning-emphasis">unpriced</span><?php endif; ?></td>
            <td class="small">
              <?php if ($s['discount_type']): ?>
                <?= $s['discount_type'] === 'percent' ? h($s['discount_value']) . '%' : format_money((float)$s['discount_value']) ?>
                <?php if ($s['discount_reason']): ?><br><span class="text-muted"><?= h($s['discount_reason']) ?></span><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end fw-semibold"><?= format_money($s['final_price']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
