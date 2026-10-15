<?php
/**
 * Payments overview — used by both admin/payments.php and accountant/payments.php.
 * The wrapper pages check the login/role and set $role before including this.
 *
 *   Tab 1 "Student balances": every student's fee, paid so far, and what's left.
 *   Tab 2 "All payments":     the full ledger of every payment, filterable.
 */
ensure_payments_table($conn);
require_once __DIR__ . '/payments_actions.php';

$instituteId = current_institute_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    process_payment_post($conn, $role, $instituteId);
}

$tab = ($_GET['tab'] ?? 'balances') === 'ledger' ? 'ledger' : 'balances';
$self = base_url($role . '/payments.php');
$studentPage = base_url($role . '/student_payments.php');
$receiptPage = base_url($role . '/receipt.php');
$exportPage = base_url($role . '/payments_export.php');
$returnQs = $_SERVER['QUERY_STRING'] ?? '';

$summary = payments_summary($conn, $instituteId);
$departments = all_departments($conn, $instituteId);

// "Receipt ready" banner shown right after recording a payment.
$newPayment = null;
if (!empty($_GET['new'])) {
    $newPayment = get_payment($conn, (int)$_GET['new'], $instituteId);
}

/* ---------- tab data ---------- */
if ($tab === 'balances') {
    $filterDept = (int)($_GET['department_id'] ?? 0);
    $filterGrade = trim($_GET['grade'] ?? '');
    $filterStatus = (string)($_GET['status'] ?? '');
    if (!in_array($filterStatus, ['paid', 'partial', 'unpaid', 'overpaid', 'unpriced'], true)) {
        $filterStatus = '';
    }
    $search = trim($_GET['q'] ?? '');
    $gradesForFilter = $filterDept ? grades_for_department($conn, $filterDept, $instituteId) : [];
    $rows = students_with_balances($conn, $instituteId, $filterDept ?: null, $filterGrade ?: null, $search, $filterStatus);
    $sum = ['fee' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'arrears' => 0.0];
    foreach ($rows as $r) {
        $sum['fee'] += $r['final_price'];
        $sum['paid'] += $r['paid'];
        $sum['remaining'] += max(0, $r['remaining']);
        $sum['arrears'] += $r['previous_remaining'];
    }
    $balancesFiltered = $filterDept || $filterGrade !== '' || $filterStatus !== '' || $search !== '';
} else {
    $filters = [
        'q'            => trim($_GET['pq'] ?? ''),
        'from'         => trim($_GET['from'] ?? ''),
        'to'           => trim($_GET['to'] ?? ''),
        'method'       => (string)($_GET['method'] ?? ''),
        'include_void' => !empty($_GET['void']),
    ];
    $perPage = 25;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $ledger = payments_ledger($conn, $instituteId, $filters, $perPage, ($page - 1) * $perPage);
    $pages = max(1, (int)ceil($ledger['count'] / $perPage));
    if ($page > $pages) {
        $page = $pages;
        $ledger = payments_ledger($conn, $instituteId, $filters, $perPage, ($page - 1) * $perPage);
    }
    $ledgerFiltered = $filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['method'] !== '' || $filters['include_void'];
    $pageQuery = function (int $p) use ($filters): string {
        return '?' . http_build_query(array_filter([
            'tab' => 'ledger', 'pq' => $filters['q'], 'from' => $filters['from'], 'to' => $filters['to'],
            'method' => $filters['method'], 'void' => $filters['include_void'] ? 1 : null, 'page' => $p,
        ], fn($v) => $v !== '' && $v !== null));
    };
}

$statusBadge = function (string $status): string {
    [$label, $color] = payment_status_meta($status);
    return '<span class="badge rounded-pill bg-' . $color . '-subtle text-' . $color . '-emphasis">' . h($label) . '</span>';
};

$pageTitle = 'Payments';
$activePage = 'payments';
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';

$counts = $summary['counts'];
$segTotal = max(1, array_sum($counts));
?>

<?php if ($newPayment): ?>
  <div class="receipt-ready mb-4">
    <div class="d-flex align-items-center gap-3">
      <span class="receipt-ready-icon"><i class="bi bi-check-lg"></i></span>
      <div>
        <div class="fw-bold">Payment #<?= (int)$newPayment['receipt_no'] ?> recorded</div>
        <div class="small text-muted"><?= h($newPayment['student_name']) ?> · <?= format_money_auto((float)$newPayment['amount']) ?> · <?= h(t(PAYMENT_METHODS[$newPayment['method']] ?? $newPayment['method'])) ?></div>
      </div>
    </div>
    <a href="<?= $receiptPage ?>?id=<?= (int)$newPayment['id'] ?>" target="_blank" class="btn btn-brand btn-sm"><i class="bi bi-printer me-1"></i>Print receipt</a>
  </div>
<?php endif; ?>

<!-- ============ Summary ============ -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card tone-indigo">
      <div class="icon-wrap"><i class="bi bi-receipt-cutoff"></i></div>
      <div>
        <div class="stat-value"><?= format_money_auto($summary['expected']) ?></div>
        <div class="stat-label">Total fees (after discounts)</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card tone-green">
      <div class="icon-wrap"><i class="bi bi-cash-coin"></i></div>
      <div>
        <div class="stat-value text-success"><?= format_money_auto($summary['collected']) ?></div>
        <div class="stat-label">Total paid</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card tone-red">
      <div class="icon-wrap"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-value text-danger"><?= format_money_auto($summary['remaining']) ?></div>
        <div class="stat-label">Remaining to collect<?= $summary['arrears'] > 0 ? ' <span title="Includes ' . h(format_money_auto($summary['arrears'])) . ' still owed from earlier grades">(incl. ' . h(format_money_auto($summary['arrears'])) . ' earlier grades)</span>' : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card tone-amber">
      <div class="icon-wrap"><i class="bi bi-calendar-check"></i></div>
      <div>
        <div class="stat-value"><?= format_money_auto($summary['today']) ?></div>
        <div class="stat-label">Collected today</div>
      </div>
    </div>
  </div>
</div>

<div class="panel fin-progress mb-4">
  <div class="row g-4 align-items-center">
    <div class="col-lg-7">
      <div class="d-flex justify-content-between align-items-end mb-2">
        <div>
          <div class="fin-progress-label">Collection progress</div>
          <div class="fin-progress-value"><?= (int)$summary['rate'] ?>%</div>
        </div>
        <div class="text-end small text-muted">
          <?= format_money_auto(min($summary['collected'], $summary['expected'])) ?> of <?= format_money_auto($summary['expected']) ?>
        </div>
      </div>
      <div class="progress fin-bar" role="progressbar" aria-valuenow="<?= (int)$summary['rate'] ?>" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar bg-success" style="width: <?= (int)$summary['rate'] ?>%"></div>
      </div>
      <div class="d-flex flex-wrap gap-4 mt-3 small">
        <div><span class="text-muted">This month</span> <strong class="ms-1"><?= format_money_auto($summary['month']) ?></strong></div>
        <div><span class="text-muted">All time (every grade)</span> <strong class="ms-1"><?= format_money_auto($summary['all_time']) ?></strong></div>
        <div><span class="text-muted">Payments</span> <strong class="ms-1"><?= (int)$summary['payments_count'] ?></strong></div>
        <?php if ($summary['credit'] > 0): ?>
          <div><span class="text-muted">Overpaid credit</span> <strong class="ms-1 text-info"><?= format_money_auto($summary['credit']) ?></strong></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="fin-progress-label mb-2">Students by status</div>
      <div class="progress fin-bar mb-2" role="progressbar">
        <div class="progress-bar bg-success" style="width: <?= $counts['paid'] / $segTotal * 100 ?>%"></div>
        <div class="progress-bar bg-warning" style="width: <?= $counts['partial'] / $segTotal * 100 ?>%"></div>
        <div class="progress-bar bg-danger" style="width: <?= $counts['unpaid'] / $segTotal * 100 ?>%"></div>
        <div class="progress-bar bg-info" style="width: <?= $counts['overpaid'] / $segTotal * 100 ?>%"></div>
        <div class="progress-bar bg-secondary" style="width: <?= $counts['unpriced'] / $segTotal * 100 ?>%"></div>
      </div>
      <div class="d-flex flex-wrap gap-3 small">
        <a class="legend-link" href="<?= $self ?>?status=paid"><span class="legend-dot bg-success"></span><?= (int)$counts['paid'] ?> paid</a>
        <a class="legend-link" href="<?= $self ?>?status=partial"><span class="legend-dot bg-warning"></span><?= (int)$counts['partial'] ?> partial</a>
        <a class="legend-link" href="<?= $self ?>?status=unpaid"><span class="legend-dot bg-danger"></span><?= (int)$counts['unpaid'] ?> unpaid</a>
        <?php if ($counts['overpaid']): ?><a class="legend-link" href="<?= $self ?>?status=overpaid"><span class="legend-dot bg-info"></span><?= (int)$counts['overpaid'] ?> overpaid</a><?php endif; ?>
        <?php if ($counts['unpriced']): ?><a class="legend-link" href="<?= $self ?>?status=unpriced"><span class="legend-dot bg-secondary"></span><?= (int)$counts['unpriced'] ?> no price</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link <?= $tab === 'balances' ? 'active' : '' ?>" href="<?= $self ?>"><i class="bi bi-people me-1"></i>Student balances</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'ledger' ? 'active' : '' ?>" href="<?= $self ?>?tab=ledger"><i class="bi bi-journal-text me-1"></i>All payments</a></li>
</ul>

<?php if ($tab === 'balances'): ?>
<!-- ============ TAB: Student balances ============ -->
<div class="panel">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="panel-title mb-0">Student balances <span class="text-muted fw-normal">· current grade</span></div>
    <a href="<?= $exportPage ?>?<?= h(http_build_query(array_filter(['type' => 'balances', 'department_id' => $filterDept ?: null, 'grade' => $filterGrade, 'status' => $filterStatus, 'q' => $search], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Export to Excel</a>
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
    <div class="col-sm-4 col-md-2">
      <label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Any status</option>
        <?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid', 'overpaid' => 'Overpaid', 'unpriced' => 'No price'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-5 col-md-3">
      <label class="form-label small mb-1">Search student</label>
      <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Student name...">
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
      <?php if ($balancesFiltered): ?><a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-people"></i>No students match that filter.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Student</th>
            <th class="text-end">Fee</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Remaining</th>
            <th style="min-width:140px">Progress</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $s): ?>
            <tr>
              <td>
                <a class="fw-semibold student-link" href="<?= $studentPage ?>?id=<?= (int)$s['id'] ?>"><?= h(student_full_name($s)) ?></a>
                <div class="text-muted small"><?= h($s['department_name']) ?> · Grade <?= h($s['grade']) ?> / <?= h($s['class_group']) ?></div>
              </td>
              <td class="text-end">
                <?= $s['base_price'] > 0 ? format_money_auto($s['final_price']) : '<span class="text-muted">—</span>' ?>
                <?php if ($s['discount_amount'] > 0): ?><div class="text-muted small">−<?= format_money_auto($s['discount_amount']) ?> discount</div><?php endif; ?>
              </td>
              <td class="text-end text-success fw-semibold"><?= format_money_auto($s['paid']) ?></td>
              <td class="text-end fw-bold <?= $s['remaining'] > 0 ? 'text-danger' : ($s['remaining'] < 0 ? 'text-info' : 'text-muted') ?>">
                <?= $s['remaining'] < 0 ? '+' . format_money_auto(-$s['remaining']) : format_money_auto($s['remaining']) ?>
                <?php if ($s['previous_remaining'] > 0): ?>
                  <a class="d-block small fw-semibold text-warning-emphasis" href="<?= $studentPage ?>?id=<?= (int)$s['id'] ?>" title="Still owed for an earlier grade"><i class="bi bi-exclamation-circle"></i> +<?= format_money_auto($s['previous_remaining']) ?> earlier grade</a>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($s['base_price'] > 0): ?>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress fin-bar-sm flex-grow-1"><div class="progress-bar <?= $s['status'] === 'paid' || $s['status'] === 'overpaid' ? 'bg-success' : 'bg-warning' ?>" style="width: <?= (int)$s['percent_paid'] ?>%"></div></div>
                    <span class="small text-muted"><?= (int)$s['percent_paid'] ?>%</span>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= $statusBadge($s['status']) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($s['remaining'] > 0 && $s['base_price'] > 0): ?>
                  <button type="button" class="btn btn-sm btn-brand js-add-payment"
                          data-student-id="<?= (int)$s['id'] ?>"
                          data-name="<?= h(student_full_name($s)) ?>"
                          data-grade="<?= h($s['grade']) ?>"
                          data-fee="<?= h((string)$s['final_price']) ?>"
                          data-paid="<?= h((string)$s['paid']) ?>"
                          data-remaining="<?= h((string)$s['remaining']) ?>">
                    <i class="bi bi-plus-lg me-1"></i>Payment
                  </button>
                <?php endif; ?>
                <a href="<?= $studentPage ?>?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Statement &amp; history"><i class="bi bi-clock-history"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fin-total-row">
            <td class="fw-bold">Total · <?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?></td>
            <td class="text-end fw-bold"><?= format_money_auto($sum['fee']) ?></td>
            <td class="text-end fw-bold text-success"><?= format_money_auto($sum['paid']) ?></td>
            <td class="text-end fw-bold text-danger"><?= format_money_auto($sum['remaining']) ?><?php if ($sum['arrears'] > 0): ?><div class="small fw-semibold text-warning-emphasis">+<?= format_money_auto($sum['arrears']) ?> earlier grades</div><?php endif; ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Add-payment modal (filled in by the "Payment" buttons above) -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content pay-modal">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_payment">
      <input type="hidden" name="return_to" value="list">
      <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
      <input type="hidden" name="student_id" id="pay-student-id">
      <input type="hidden" name="grade" id="pay-grade">
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title fw-bold" id="pay-name">Record payment</h5>
          <div class="text-muted small" id="pay-sub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="pay-balance-strip mb-3">
          <div><div class="small text-muted">Fee</div><div class="fw-bold" id="pay-fee">0</div></div>
          <div><div class="small text-muted">Paid</div><div class="fw-bold text-success" id="pay-paid">0</div></div>
          <div><div class="small text-muted">Remaining</div><div class="fw-bold text-danger" id="pay-remaining">0</div></div>
        </div>
        <div class="mb-3">
          <label class="form-label">Amount received</label>
          <div class="input-group">
            <input type="number" name="amount" id="pay-amount" class="form-control form-control-lg" min="0.01" step="0.01" required inputmode="decimal">
            <button type="button" class="btn btn-outline-secondary" id="pay-full">Full balance</button>
          </div>
          <div class="form-text" id="pay-after"></div>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="date" name="paid_at" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label">Method</label>
            <select name="method" class="form-select">
              <?php foreach (PAYMENT_METHODS as $k => $label): ?><option value="<?= $k ?>"><?= h($label) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Reference <span class="text-muted fw-normal">(optional — transfer / cheque no.)</span></label>
            <input type="text" name="reference" class="form-control" maxlength="100">
          </div>
          <div class="col-12">
            <label class="form-label">Note <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. 1st instalment">
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand px-4"><i class="bi bi-check2-circle me-1"></i>Save payment</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modalEl = document.getElementById('payModal');
  if (!modalEl) return;
  var modal = new bootstrap.Modal(modalEl);
  var L = <?= json_encode([
      'more' => t('More than the remaining balance ({}).'), 'clears' => t('This clears the balance in full.'),
      'after' => t('Remaining after this payment: {}'), 'feeFor' => t('Grade {} fee'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var remaining = 0;
  function fmt(n) {
    var whole = Math.abs(n - Math.round(n)) < 0.005;
    return n.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
  }
  var amount = document.getElementById('pay-amount');
  function updateAfter() {
    var v = parseFloat(amount.value);
    var out = document.getElementById('pay-after');
    if (isNaN(v) || v <= 0) { out.textContent = ''; out.className = 'form-text'; return; }
    var left = remaining - v;
    if (left < -0.004) { out.textContent = L.more.replace('{}', fmt(remaining)); out.className = 'form-text text-danger'; }
    else if (left <= 0.004) { out.textContent = L.clears; out.className = 'form-text text-success'; }
    else { out.textContent = L.after.replace('{}', fmt(left)); out.className = 'form-text'; }
  }
  document.querySelectorAll('.js-add-payment').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = btn.dataset;
      remaining = parseFloat(d.remaining);
      document.getElementById('pay-student-id').value = d.studentId;
      document.getElementById('pay-grade').value = d.grade;
      document.getElementById('pay-name').textContent = d.name;
      document.getElementById('pay-sub').textContent = L.feeFor.replace('{}', d.grade);
      document.getElementById('pay-fee').textContent = fmt(parseFloat(d.fee));
      document.getElementById('pay-paid').textContent = fmt(parseFloat(d.paid));
      document.getElementById('pay-remaining').textContent = fmt(remaining);
      amount.value = '';
      amount.max = remaining;
      updateAfter();
      modal.show();
    });
  });
  document.getElementById('pay-full').addEventListener('click', function () { amount.value = remaining; updateAfter(); amount.focus(); });
  amount.addEventListener('input', updateAfter);
  modalEl.addEventListener('shown.bs.modal', function () { amount.focus(); });
});
</script>

<?php else: ?>
<!-- ============ TAB: All payments (ledger) ============ -->
<div class="panel">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="panel-title mb-0">All payments</div>
    <a href="<?= $exportPage ?>?<?= h(http_build_query(array_filter(['type' => 'ledger', 'pq' => $filters['q'], 'from' => $filters['from'], 'to' => $filters['to'], 'method' => $filters['method'], 'void' => $filters['include_void'] ? 1 : null], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Export to Excel</a>
  </div>

  <form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="ledger">
    <div class="col-sm-6 col-md-3">
      <label class="form-label small mb-1">Search</label>
      <input type="text" name="pq" class="form-control form-control-sm" value="<?= h($filters['q']) ?>" placeholder="Student, receipt # or reference">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= h($filters['from']) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= h($filters['to']) ?>">
    </div>
    <div class="col-sm-4 col-md-2">
      <label class="form-label small mb-1">Method</label>
      <select name="method" class="form-select form-select-sm">
        <option value="">All methods</option>
        <?php foreach (PAYMENT_METHODS as $k => $label): ?><option value="<?= $k ?>" <?= $filters['method'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-4 col-md-3 d-flex align-items-center gap-2 flex-wrap">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" name="void" value="1" id="f-void" <?= $filters['include_void'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="f-void">Show voided</label>
      </div>
      <button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
      <?php if ($ledgerFiltered): ?><a href="<?= $self ?>?tab=ledger" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
    </div>
  </form>

  <div class="fin-ledger-total mb-3">
    <div><span class="text-muted small">Payments found</span><div class="fw-bold fs-5"><?= (int)$ledger['count'] ?></div></div>
    <div><span class="text-muted small">Total received</span><div class="fw-bold fs-5 text-success"><?= format_money_auto($ledger['total']) ?></div></div>
  </div>

  <?php if (!$ledger['rows']): ?>
    <div class="empty-state"><i class="bi bi-receipt"></i><?= $ledgerFiltered ? 'No payments match those filters.' : 'No payments recorded yet. Use the “Payment” button on the Student balances tab to record the first one.' ?></div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Receipt</th><th>Date</th><th>Student</th><th>Grade</th><th>Method</th>
            <th class="text-end">Amount</th><th>Recorded by</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger['rows'] as $p): $void = (int)$p['is_void'] === 1; ?>
            <tr class="<?= $void ? 'row-void' : '' ?>">
              <td class="fw-semibold">#<?= (int)$p['receipt_no'] ?></td>
              <td class="text-nowrap"><?= h(format_pay_date($p['paid_at'])) ?></td>
              <td>
                <a class="student-link fw-semibold" href="<?= $studentPage ?>?id=<?= (int)$p['student_id'] ?>"><?= h($p['student_name']) ?></a>
                <?php if ($p['note']): ?><div class="text-muted small"><?= h($p['note']) ?></div><?php endif; ?>
                <?php if ($void): ?><div class="small text-danger"><i class="bi bi-x-circle me-1"></i>Voided — <?= h($p['void_reason']) ?> <span class="text-muted">(<?= h($p['voided_by_name']) ?>)</span></div><?php endif; ?>
              </td>
              <td>Grade <?= h($p['grade']) ?></td>
              <td>
                <?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?>
                <?php if ($p['reference']): ?><div class="text-muted small"><?= h($p['reference']) ?></div><?php endif; ?>
              </td>
              <td class="text-end fw-bold <?= $void ? 'text-muted' : 'text-success' ?>"><?= format_money_auto((float)$p['amount']) ?></td>
              <td class="small text-muted"><?= h($p['recorded_by_name']) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($void): ?>
                  <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis">Void</span>
                <?php else: ?>
                  <a href="<?= $receiptPage ?>?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print receipt"><i class="bi bi-printer"></i></a>
                  <button type="button" class="btn btn-sm btn-outline-danger js-void" data-id="<?= (int)$p['id'] ?>" data-label="#<?= (int)$p['receipt_no'] ?> · <?= h($p['student_name']) ?> · <?= h(format_money_auto((float)$p['amount'])) ?>" title="Void payment"><i class="bi bi-slash-circle"></i></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="d-flex justify-content-between align-items-center mt-3">
        <div class="small text-muted">Page <?= $page ?> of <?= $pages ?></div>
        <div class="btn-group">
          <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $pageQuery($page - 1) ?>"><i class="bi bi-chevron-left"></i> Previous</a>
          <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $pageQuery($page + 1) ?>">Next <i class="bi bi-chevron-right"></i></a>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$voidReturnTo = 'list';
$voidReturnQs = $returnQs;
$voidStudentId = 0;
require __DIR__ . '/payments_void_modal.php';
?>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
