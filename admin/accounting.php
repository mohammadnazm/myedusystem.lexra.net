<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_price') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $grade = trim($_POST['grade'] ?? '');
        $price = parse_money_input($_POST['price'] ?? '');

        if (!$departmentId || $grade === '') {
            flash_set('danger', 'Department and grade are required.');
        } elseif (!department_belongs_to_institute($conn, $departmentId, $instituteId)) {
            flash_set('danger', 'Department not found.');
        } elseif ($price === null) {
            flash_set('danger', 'Enter the price as a plain number, e.g. 1500000 (commas are fine, up to 2 decimals).');
        } else {
            set_department_price($conn, $departmentId, $grade, $price);
            $deptRow = $conn->query("SELECT name FROM departments WHERE id = " . (int)$departmentId)->fetch_assoc();
            $deptName = $deptRow['name'] ?? "#$departmentId";
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Set price for $deptName / grade $grade: " . format_money($price));
            flash_set('success', 'Price saved.');
        }
    } elseif ($action === 'delete_price') {
        $id = (int)($_POST['id'] ?? 0);
        if (delete_department_price($conn, $id, $instituteId)) {
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Removed a department price (#$id)");
            flash_set('success', 'Price removed.');
        } else {
            flash_set('danger', 'Price not found.');
        }
    } elseif ($action === 'set_discounts') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $student = student_belongs_to_institute($conn, $studentId, $instituteId) ? get_student($conn, $studentId, $instituteId) : null;
        $types = $_POST['discount_type'] ?? [];
        $values = $_POST['discount_value'] ?? [];
        $reasons = $_POST['reason'] ?? [];

        if (!$student) {
            flash_set('danger', 'Student not found.');
        } else {
            $grades = priced_grades_for_department($conn, (int)$student['department_id']);
            if (!in_array($student['grade'], $grades, true)) {
                $grades[] = $student['grade'];
            }
            $discounts = [];
            $invalid = false;
            foreach ($grades as $grade) {
                $rawValue = trim((string)($values[$grade] ?? ''));
                if ($rawValue === '') {
                    continue;
                }
                $type = ($types[$grade] ?? '') === 'percent' ? 'percent' : 'fixed';
                $value = parse_money_input($rawValue);
                if ($value === null || $value < 0 || ($type === 'percent' && $value > 100)) {
                    $invalid = true;
                    break;
                }
                $reason = trim((string)($reasons[$grade] ?? ''));
                $discounts[$grade] = ['type' => $type, 'value' => $value, 'reason' => $reason !== '' ? $reason : null];
            }

            if ($invalid) {
                flash_set('danger', 'Enter a valid discount for each grade (0–100 for a percentage, or a fixed amount ≥ 0).');
            } else {
                set_student_discounts_bulk($conn, $studentId, $grades, $discounts, current_user_id());
                $count = count($discounts);
                log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Set $count grade discount(s) for \"" . student_full_name($student) . "\"");
                flash_set('success', 'Discounts saved.');
            }
        }
    } elseif ($action === 'clear_discount') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $student = student_belongs_to_institute($conn, $studentId, $instituteId) ? get_student($conn, $studentId, $instituteId) : null;
        if ($student) {
            clear_student_discount($conn, $studentId);
            log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Removed all grade discounts for \"" . student_full_name($student) . "\"");
            flash_set('success', 'Discounts removed.');
        }
    }
    header('Location: ' . base_url('admin/accounting.php') . '#' . ($_POST['return_tab'] ?? 'pricing'));
    exit;
}

$departments = all_departments($conn, $instituteId);
$gradesByDeptJson = [];
foreach ($departments as $d) {
    $gradesByDeptJson[(string)$d['id']] = grades_for_department($conn, (int)$d['id'], $instituteId);
}
$prices = department_prices($conn, $instituteId);
$pricesByDept = [];
foreach ($prices as $p) {
    $pricesByDept[$p['department_id']][] = $p;
}

$filterDept = (int)($_GET['department_id'] ?? 0);
$filterGrade = trim($_GET['grade'] ?? '');
$search = trim($_GET['q'] ?? '');
$studentRows = students_with_pricing($conn, $instituteId, $filterDept ?: null, $filterGrade ?: null, $search);
$gradesForFilter = $filterDept ? grades_for_department($conn, $filterDept, $instituteId) : [];
$discountsMap = student_discounts_map($conn, array_column($studentRows, 'id'));
$summary = accounting_summary($conn, $instituteId);

$pageTitle = 'Accounting';
$role = 'admin';
$activePage = 'accounting';
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
  <div><i class="bi bi-receipt me-2 text-primary"></i><strong>Student payments</strong> <span class="text-muted">— record what students pay and see totals and remaining balances.</span></div>
  <a href="<?= base_url('admin/payments.php') ?>" class="btn btn-sm btn-brand">Open Payments <i class="bi bi-arrow-right ms-1"></i></a>
</div>

<?php if ($summary['unpriced'] > 0): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= $summary['unpriced'] ?> student(s) are in a department/grade with no price set yet — they show as 0 below until you price that grade.</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="pricing-tab" data-bs-toggle="tab" data-bs-target="#pricing" type="button" role="tab">
      <i class="bi bi-diagram-3 me-1"></i>Department Pricing
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="discounts-tab" data-bs-toggle="tab" data-bs-target="#discounts" type="button" role="tab">
      <i class="bi bi-percent me-1"></i>Student Discounts
    </button>
  </li>
</ul>

<div class="tab-content">
  <!-- ===================== PRICING TAB ===================== -->
  <div class="tab-pane fade show active" id="pricing" role="tabpanel">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="panel">
          <div class="panel-title">Set a price</div>
          <?php if (!$departments): ?>
            <div class="alert alert-warning py-2">Add a department first.</div>
          <?php else: ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_price">
              <input type="hidden" name="return_tab" value="pricing">
              <div class="mb-3">
                <label class="form-label">Department</label>
                <select name="department_id" id="price-dept" class="form-select" required onchange="updateGradeList()">
                  <option value="">Select department</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Grade</label>
                <input type="text" name="grade" id="price-grade" class="form-control" list="grade-options" placeholder="e.g. 10" required>
                <datalist id="grade-options"></datalist>
                <div class="form-text">Matches the grade used in Class Sections. A new grade name is fine too.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Price</label>
                <input type="number" name="price" class="form-control" min="0" step="0.01" required>
              </div>
              <button type="submit" class="btn btn-brand w-100">Save price</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="panel">
          <div class="panel-title">Current prices (<?= count($prices) ?>)</div>
          <?php if (!$prices): ?>
            <div class="empty-state"><i class="bi bi-cash-coin"></i>No prices set yet.</div>
          <?php else: ?>
            <div class="table-scroll">
            <table class="table">
              <thead><tr><th>Department</th><th>Grade</th><th class="text-end">Price</th><th class="text-muted small">Updated</th><th class="text-end">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($prices as $p): ?>
                  <tr>
                    <td class="fw-semibold"><?= h($p['department_name']) ?></td>
                    <td>Grade <?= h($p['grade']) ?></td>
                    <td class="text-end"><?= format_money((float)$p['price']) ?></td>
                    <td class="text-muted small"><?= h($p['updated_at']) ?></td>
                    <td class="text-end">
                      <form method="post" onsubmit="return confirm('Remove this price?');" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_price">
                        <input type="hidden" name="return_tab" value="pricing">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
  </div>

  <!-- ===================== DISCOUNTS TAB ===================== -->
  <div class="tab-pane fade" id="discounts" role="tabpanel">
    <div class="panel">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="panel-title mb-0">Student fees &amp; discounts</div>
        <a href="<?= base_url('admin/discounts_export.php') ?>?<?= http_build_query(['department_id' => $filterDept ?: null, 'grade' => $filterGrade ?: null, 'q' => $search ?: null]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Export discounted students to Excel</a>
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
            <a href="<?= base_url('admin/accounting.php') ?>#discounts" class="btn btn-sm btn-outline-secondary">Clear</a>
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
              <th class="text-end">Final price</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($studentRows as $s):
              $deptPrices = $pricesByDept[$s['department_id']] ?? [];
              $studentGrades = array_column($deptPrices, 'grade');
              if (!in_array($s['grade'], $studentGrades, true)) {
                  $studentGrades[] = $s['grade'];
              }
              $studentDiscounts = $discountsMap[$s['id']] ?? [];
              $priceByGrade = [];
              foreach ($deptPrices as $dp) { $priceByGrade[$dp['grade']] = (float)$dp['price']; }
            ?>
              <tr>
                <td class="fw-semibold"><?= h(student_full_name($s)) ?></td>
                <td class="text-muted small"><?= h($s['department_name']) ?></td>
                <td class="text-muted small">Grade <?= h($s['grade']) ?> / <?= h($s['class_group']) ?></td>
                <td class="text-end"><?= format_money($s['base_price']) ?><?php if ($s['base_price'] <= 0): ?> <span class="badge text-bg-warning-subtle text-warning-emphasis">unpriced</span><?php endif; ?></td>
                <td class="small">
                  <?php if ($s['discount_type']): ?>
                    <?= $s['discount_type'] === 'percent' ? h($s['discount_value']) . '%' : format_money((float)$s['discount_value']) ?>
                    <span class="text-muted">(grade <?= h($s['grade']) ?>)</span>
                    <?php if ($s['discount_reason']): ?><br><span class="text-muted"><?= h($s['discount_reason']) ?></span><?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                  <?php if (count($studentDiscounts) > ($s['discount_type'] ? 1 : 0)): ?>
                    <br><span class="text-muted small"><?= count($studentDiscounts) ?> grade(s) set total</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-semibold"><?= format_money($s['final_price']) ?></td>
                <td class="text-end text-nowrap">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#disc-<?= (int)$s['id'] ?>" title="Set discounts per grade"><i class="bi bi-percent"></i></button>
                  <?php if ($studentDiscounts): ?>
                    <form method="post" onsubmit="return confirm('Remove ALL of this student\'s grade discounts?');" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="clear_discount">
                      <input type="hidden" name="return_tab" value="discounts">
                      <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <tr class="collapse" id="disc-<?= (int)$s['id'] ?>">
                <td colspan="7">
                  <form method="post" class="py-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_discounts">
                    <input type="hidden" name="return_tab" value="discounts">
                    <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                    <div class="form-text mb-2">This student's department (<?= h($s['department_name']) ?>) has <?= count($studentGrades) ?> grade(s), each priced separately — set a discount for as many as you like. A grade left blank keeps no discount; it applies automatically once the student is promoted into that grade.</div>
                    <div class="table-scroll">
                    <table class="table table-sm align-middle mb-2">
                      <thead><tr><th>Grade</th><th class="text-end">Price</th><th>Type</th><th>Value</th><th>Reason (optional)</th></tr></thead>
                      <tbody>
                        <?php foreach ($studentGrades as $g): $existing = $studentDiscounts[$g] ?? null; ?>
                          <tr<?= $g === $s['grade'] ? ' class="table-active"' : '' ?>>
                            <td class="fw-semibold">Grade <?= h($g) ?><?= $g === $s['grade'] ? ' <span class="badge text-bg-secondary">current</span>' : '' ?></td>
                            <td class="text-end text-muted small"><?= isset($priceByGrade[$g]) ? format_money($priceByGrade[$g]) : '—' ?></td>
                            <td>
                              <select name="discount_type[<?= h($g) ?>]" class="form-select form-select-sm">
                                <option value="percent" <?= $existing && $existing['discount_type'] === 'percent' ? 'selected' : '' ?>>Percent %</option>
                                <option value="fixed" <?= !$existing || $existing['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
                              </select>
                            </td>
                            <td>
                              <input type="number" name="discount_value[<?= h($g) ?>]" class="form-control form-control-sm" min="0" step="0.01" value="<?= $existing ? h((string)$existing['discount_value']) : '' ?>">
                            </td>
                            <td>
                              <input type="text" name="reason[<?= h($g) ?>]" class="form-control form-control-sm" value="<?= h($existing['reason'] ?? '') ?>" placeholder="e.g. sibling discount">
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                    </div>
                    <button type="submit" class="btn btn-sm btn-brand">Save discounts for all grades</button>
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

<script>
const gradesByDept = <?= json_encode($gradesByDeptJson) ?>;
function updateGradeList() {
  const deptId = document.getElementById('price-dept').value;
  const list = document.getElementById('grade-options');
  list.innerHTML = '';
  (gradesByDept[deptId] || []).forEach(function (g) {
    const opt = document.createElement('option');
    opt.value = g;
    list.appendChild(opt);
  });
}
document.addEventListener('DOMContentLoaded', function () {
  const params = new URLSearchParams(window.location.search);
  const hasDiscountFilter = params.has('department_id') || params.has('grade') || params.has('q');
  if (window.location.hash === '#discounts' || hasDiscountFilter) {
    new bootstrap.Tab(document.getElementById('discounts-tab')).show();
    if (!window.location.hash) {
      history.replaceState(null, '', window.location.pathname + window.location.search + '#discounts');
    }
  }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>