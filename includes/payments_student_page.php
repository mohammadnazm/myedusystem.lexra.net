<?php
/**
 * One student's payment statement — used by admin/student_payments.php and
 * accountant/student_payments.php (the wrapper checks the role and sets $role).
 *
 * Shows total fees, total paid and remaining, a breakdown per grade, every payment
 * ever recorded for the student, and a form to record a new one.
 */
ensure_payments_table($conn);
require_once __DIR__ . '/payments_actions.php';

$instituteId = current_institute_id();
$self = base_url($role . '/payments.php');
$studentPage = base_url($role . '/student_payments.php');
$receiptPage = base_url($role . '/receipt.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    process_payment_post($conn, $role, $instituteId);
}

$studentId = (int)($_GET['id'] ?? 0);
$student = $studentId ? get_student_for_payments($conn, $studentId, $instituteId) : null;
if (!$student) {
    flash_set('danger', 'Student not found. If this keeps happening, the database may need repairing — see check_database.php in the README.');
    header('Location: ' . $self);
    exit;
}

$statement = student_statement($conn, $student);
$totals = $statement['totals'];
$activePayments = array_filter($statement['payments'], fn($p) => !$p['is_void']);

// Grades a payment can be recorded against (priced ones, plus the current grade).
$gradeChoices = [];
$allowed = priced_grades_for_department($conn, (int)$student['department_id']);
if (!in_array($student['grade'], $allowed, true)) {
    $allowed[] = $student['grade'];
}
usort($allowed, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
foreach ($allowed as $g) {
    $fee = student_grade_fee($conn, $studentId, (int)$student['department_id'], $g);
    $paid = student_paid_for_grade($conn, $studentId, $g);
    $gradeChoices[] = ['grade' => $g, 'priced' => $fee['priced'], 'fee' => $fee['fee'], 'paid' => $paid, 'remaining' => round($fee['fee'] - $paid, 2)];
}
$currentRemaining = 0.0;
foreach ($gradeChoices as $gc) {
    if ($gc['grade'] === $student['grade']) {
        $currentRemaining = max(0, $gc['remaining']);
    }
}
$canRecord = false;
foreach ($gradeChoices as $gc) {
    if ($gc['priced'] && $gc['remaining'] > 0) {
        $canRecord = true;
    }
}

$newPayment = !empty($_GET['new']) ? get_payment($conn, (int)$_GET['new'], $instituteId) : null;
if ($newPayment && (int)$newPayment['student_id'] !== $studentId) {
    $newPayment = null;
}

$statusBadge = function (string $status): string {
    [$label, $color] = payment_status_meta($status);
    return '<span class="badge rounded-pill bg-' . $color . '-subtle text-' . $color . '-emphasis">' . h($label) . '</span>';
};

$overallStatus = $totals['fee'] <= 0 ? 'unpriced' : payment_status(true, $totals['fee'], $totals['paid']);
$instituteRow = get_institute($conn, $instituteId);
$printLogo = institute_logo_data_uri($instituteRow, 300);
$printName = trim((string)($instituteRow['name'] ?? '')) !== '' ? $instituteRow['name'] : (current_institute_name() ?? '');
$initials = mb_strtoupper(mb_substr($student['first_name'] ?? '', 0, 1) . mb_substr($student['last_name'] ?? ($student['second_name'] ?? ''), 0, 1));

$pageTitle = 'Student payments';
$activePage = 'payments';
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
?>
<div class="print-header">
  <div class="print-header-logo"><?php if ($printLogo): ?><img src="<?= $printLogo ?>" alt=""><?php endif; ?></div>
  <div class="print-header-title">
    <div class="print-header-name" dir="auto"><?= h($printName) ?></div>
    <div class="print-header-sub">Student Payment Statement</div>
  </div>
  <div></div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
  <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to payments</a>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print statement</button>
</div>

<?php if ($newPayment): ?>
  <div class="receipt-ready mb-4 no-print">
    <div class="d-flex align-items-center gap-3">
      <span class="receipt-ready-icon"><i class="bi bi-check-lg"></i></span>
      <div>
        <div class="fw-bold">Payment #<?= (int)$newPayment['receipt_no'] ?> recorded</div>
        <div class="small text-muted"><?= format_money_auto((float)$newPayment['amount']) ?> · <?= h(t(PAYMENT_METHODS[$newPayment['method']] ?? $newPayment['method'])) ?> · <?= h(format_pay_date($newPayment['paid_at'])) ?></div>
      </div>
    </div>
    <a href="<?= $receiptPage ?>?id=<?= (int)$newPayment['id'] ?>" target="_blank" class="btn btn-brand btn-sm"><i class="bi bi-printer me-1"></i>Print receipt</a>
  </div>
<?php endif; ?>

<!-- Student header -->
<div class="panel stu-head">
  <div class="stu-avatar"><?= h($initials ?: '?') ?></div>
  <div class="flex-grow-1">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <h2 class="h4 fw-bold mb-0"><?= h(student_full_name($student)) ?></h2>
      <?= $statusBadge($overallStatus) ?>
      <?php if (!(int)$student['is_active']): ?><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">Inactive</span><?php endif; ?>
    </div>
    <div class="text-muted">
      <?= h($student['department_name']) ?> · Grade <?= h($student['grade']) ?> / <?= h($student['class_group']) ?>
      <?php if (!empty($student['phone'])): ?> · <i class="bi bi-telephone"></i> <?= h($student['phone']) ?><?php endif; ?>
    </div>
  </div>
  <div class="text-muted small text-end d-none d-md-block">Statement date<br><strong><?= h(format_pay_date(date('Y-m-d'))) ?></strong></div>
</div>

<!-- Totals -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card tone-indigo">
      <div class="icon-wrap"><i class="bi bi-receipt-cutoff"></i></div>
      <div>
        <div class="stat-value"><?= format_money_auto($totals['fee']) ?></div>
        <div class="stat-label">Total fees<?= $totals['discount'] > 0 ? ' (after ' . format_money_auto($totals['discount']) . ' discount)' : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card tone-green">
      <div class="icon-wrap"><i class="bi bi-cash-coin"></i></div>
      <div>
        <div class="stat-value text-success"><?= format_money_auto($totals['paid']) ?></div>
        <div class="stat-label">Total paid · <?= count($activePayments) ?> payment<?= count($activePayments) === 1 ? '' : 's' ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card <?= $totals['remaining'] > 0 ? 'tone-red' : 'tone-green' ?>">
      <div class="icon-wrap"><i class="bi <?= $totals['remaining'] > 0 ? 'bi-hourglass-split' : 'bi-patch-check' ?>"></i></div>
      <div>
        <div class="stat-value <?= $totals['remaining'] > 0 ? 'text-danger' : ($totals['remaining'] < 0 ? 'text-info' : 'text-success') ?>"><?= $totals['remaining'] < 0 ? '+' . format_money_auto(-$totals['remaining']) : format_money_auto($totals['remaining']) ?></div>
        <div class="stat-label"><?= $totals['remaining'] > 0 ? 'Remaining balance' : ($totals['remaining'] < 0 ? 'Overpaid (credit)' : 'Nothing remaining') ?></div>
      </div>
    </div>
  </div>
</div>
<div class="progress fin-bar mb-4" role="progressbar" aria-valuenow="<?= (int)$totals['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
  <div class="progress-bar bg-success" style="width: <?= (int)$totals['percent'] ?>%"><?= (int)$totals['percent'] ?>% paid</div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <!-- Per-grade breakdown -->
    <div class="panel">
      <div class="panel-title">Fees by grade</div>
      <div class="table-scroll table-scroll-sm" style="max-height:none">
        <table class="table align-middle">
          <thead><tr><th>Grade</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Fee</th><th class="text-end">Paid</th><th class="text-end">Remaining</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($statement['grades'] as $g): ?>
              <tr>
                <td class="fw-semibold">Grade <?= h($g['grade']) ?><?= $g['is_current'] ? ' <span class="badge text-bg-secondary">current</span>' : '' ?></td>
                <td class="text-end"><?= $g['priced'] ? format_money_auto($g['base']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end text-muted"><?= $g['discount'] > 0 ? '−' . format_money_auto($g['discount']) : '—' ?></td>
                <td class="text-end fw-semibold"><?= format_money_auto($g['fee']) ?></td>
                <td class="text-end text-success fw-semibold"><?= format_money_auto($g['paid']) ?></td>
                <td class="text-end fw-bold <?= $g['remaining'] > 0 ? 'text-danger' : ($g['remaining'] < 0 ? 'text-info' : 'text-muted') ?>"><?= $g['remaining'] < 0 ? '+' . format_money_auto(-$g['remaining']) : format_money_auto($g['remaining']) ?></td>
                <td><?= $statusBadge($g['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if (count($statement['grades']) > 1): ?>
            <tfoot>
              <tr class="fin-total-row">
                <td class="fw-bold">Total</td><td></td>
                <td class="text-end text-muted"><?= $totals['discount'] > 0 ? '−' . format_money_auto($totals['discount']) : '—' ?></td>
                <td class="text-end fw-bold"><?= format_money_auto($totals['fee']) ?></td>
                <td class="text-end fw-bold text-success"><?= format_money_auto($totals['paid']) ?></td>
                <td class="text-end fw-bold text-danger"><?= format_money_auto(max(0, $totals['remaining'])) ?></td>
                <td></td>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Payment history -->
    <div class="panel">
      <div class="panel-title">Payment history</div>
      <?php if (!$statement['payments']): ?>
        <div class="empty-state"><i class="bi bi-receipt"></i>No payments recorded for this student yet.</div>
      <?php else: ?>
        <div class="table-scroll" style="max-height:none">
          <table class="table align-middle">
            <thead><tr><th>Receipt</th><th>Date</th><th>Grade</th><th>Method</th><th class="text-end">Amount</th><th class="text-end no-print">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($statement['payments'] as $p): $void = (int)$p['is_void'] === 1; ?>
                <tr class="<?= $void ? 'row-void' : '' ?>">
                  <td class="fw-semibold">#<?= (int)$p['receipt_no'] ?></td>
                  <td class="text-nowrap"><?= h(format_pay_date($p['paid_at'])) ?></td>
                  <td>Grade <?= h($p['grade']) ?></td>
                  <td>
                    <?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?>
                    <?php if ($p['reference']): ?><div class="text-muted small"><?= h($p['reference']) ?></div><?php endif; ?>
                    <?php if ($p['note']): ?><div class="text-muted small"><?= h($p['note']) ?></div><?php endif; ?>
                    <?php if ($void): ?><div class="small text-danger"><i class="bi bi-x-circle me-1"></i>Voided — <?= h($p['void_reason']) ?> <span class="text-muted">(<?= h($p['voided_by_name']) ?>)</span></div><?php endif; ?>
                    <div class="text-muted small">by <?= h($p['recorded_by_name']) ?></div>
                  </td>
                  <td class="text-end fw-bold <?= $void ? 'text-muted' : 'text-success' ?>"><?= format_money_auto((float)$p['amount']) ?></td>
                  <td class="text-end text-nowrap no-print">
                    <?php if ($void): ?>
                      <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis">Void</span>
                    <?php else: ?>
                      <a href="<?= $receiptPage ?>?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print receipt"><i class="bi bi-printer"></i></a>
                      <button type="button" class="btn btn-sm btn-outline-danger js-void" data-id="<?= (int)$p['id'] ?>" data-label="#<?= (int)$p['receipt_no'] ?> · <?= h(format_money_auto((float)$p['amount'])) ?> · <?= h(format_pay_date($p['paid_at'])) ?>" title="Void payment"><i class="bi bi-slash-circle"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="fin-total-row">
                <td colspan="4" class="fw-bold">Total paid</td>
                <td class="text-end fw-bold text-success"><?= format_money_auto($totals['paid']) ?></td>
                <td class="no-print"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Record payment -->
  <div class="col-lg-4 no-print">
    <div class="panel pay-form-panel">
      <div class="panel-title"><i class="bi bi-plus-circle me-1 text-primary"></i>Record a payment</div>
      <?php if (!$canRecord): ?>
        <div class="alert alert-success py-2 mb-0"><i class="bi bi-patch-check me-1"></i>Nothing is owed for any priced grade, so there's nothing to record right now.</div>
      <?php else: ?>
        <form method="post" id="stu-pay-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_payment">
          <input type="hidden" name="return_to" value="student">
          <input type="hidden" name="return_qs" value="">
          <input type="hidden" name="student_id" value="<?= $studentId ?>">
          <div class="mb-3">
            <label class="form-label">Applies to grade</label>
            <select name="grade" id="sp-grade" class="form-select">
              <?php foreach ($gradeChoices as $gc): if (!$gc['priced']) continue; ?>
                <option value="<?= h($gc['grade']) ?>" data-remaining="<?= h((string)max(0, $gc['remaining'])) ?>" <?= $gc['grade'] === $student['grade'] ? 'selected' : '' ?>>
                  Grade <?= h($gc['grade']) ?><?= $gc['grade'] === $student['grade'] ? ' (current)' : '' ?> — <?= $gc['remaining'] > 0 ? format_money_auto($gc['remaining']) . ' remaining' : 'paid' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount received</label>
            <div class="input-group">
              <input type="number" name="amount" id="sp-amount" class="form-control form-control-lg" min="0.01" step="0.01" required inputmode="decimal">
              <button type="button" class="btn btn-outline-secondary" id="sp-full">Full balance</button>
            </div>
            <div class="form-text" id="sp-after"></div>
          </div>
          <div class="row g-3 mb-3">
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
          </div>
          <div class="mb-3">
            <label class="form-label">Reference <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" name="reference" class="form-control" maxlength="100" placeholder="Transfer / cheque no.">
          </div>
          <div class="mb-3">
            <label class="form-label">Note <span class="text-muted fw-normal">(optional)</span></label>
            <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. 1st instalment">
          </div>
          <button type="submit" class="btn btn-brand w-100 py-2"><i class="bi bi-check2-circle me-1"></i>Save payment</button>
        </form>
        <script>
        (function () {
          var L = <?= json_encode([
              'more' => t('More than the remaining balance ({}).'), 'clears' => t('This clears the balance in full.'),
              'after' => t('Remaining after this payment: {}'),
          ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
          var grade = document.getElementById('sp-grade');
          var amount = document.getElementById('sp-amount');
          var after = document.getElementById('sp-after');
          function remaining() { return parseFloat(grade.options[grade.selectedIndex].dataset.remaining) || 0; }
          function fmt(n) {
            var whole = Math.abs(n - Math.round(n)) < 0.005;
            return n.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
          }
          function update() {
            var v = parseFloat(amount.value), r = remaining();
            amount.max = r;
            if (isNaN(v) || v <= 0) { after.textContent = ''; after.className = 'form-text'; return; }
            var left = r - v;
            if (left < -0.004) { after.textContent = L.more.replace('{}', fmt(r)); after.className = 'form-text text-danger'; }
            else if (left <= 0.004) { after.textContent = L.clears; after.className = 'form-text text-success'; }
            else { after.textContent = L.after.replace('{}', fmt(left)); after.className = 'form-text'; }
          }
          document.getElementById('sp-full').addEventListener('click', function () { amount.value = remaining(); update(); amount.focus(); });
          amount.addEventListener('input', update);
          grade.addEventListener('change', update);
          update();
        })();
        </script>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$voidReturnTo = 'student';
$voidReturnQs = '';
$voidStudentId = $studentId;
require __DIR__ . '/payments_void_modal.php';
require __DIR__ . '/footer.php';
