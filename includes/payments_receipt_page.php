<?php
/**
 * Printable receipt for one payment (stand-alone page, no sidebar). Shared by
 * admin/receipt.php and accountant/receipt.php. Open it in a new tab and print.
 */
ensure_payments_table($conn);

$instituteId = current_institute_id();
$payment = get_payment($conn, (int)($_GET['id'] ?? 0), $instituteId);
if (!$payment) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;max-width:560px;margin:60px auto;">Receipt not found.</p>';
    exit;
}

$student = get_student_for_payments($conn, (int)$payment['student_id'], $instituteId);

// Fee for the grade this payment was for, and the balance right after it.
$fee = null;
$paidToDate = null;
if ($student) {
    $fee = student_grade_fee($conn, (int)$payment['student_id'], (int)$student['department_id'], $payment['grade']);
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS paid FROM student_payments
         WHERE student_id = ? AND grade = ? AND is_void = 0
           AND (paid_at < ? OR (paid_at = ? AND id <= ?))"
    );
    $sid = (int)$payment['student_id'];
    $pid = (int)$payment['id'];
    $stmt->bind_param('isssi', $sid, $payment['grade'], $payment['paid_at'], $payment['paid_at'], $pid);
    $stmt->execute();
    $paidToDate = (float)$stmt->get_result()->fetch_assoc()['paid'];
    $stmt->close();
}

$void = (int)$payment['is_void'] === 1;
$institute = get_institute($conn, $instituteId);
// Embedded in the page itself so the logo always shows when printing or saving as PDF.
$logo = institute_logo_data_uri($institute, 400);
$instituteName = trim((string)($institute['name'] ?? '')) !== '' ? $institute['name'] : (current_institute_name() ?? '');
$assetBase = base_url('assets');
i18n_start();
$bootstrapCss = i18n_is_rtl() ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
$backUrl = $student
    ? base_url($role . '/student_payments.php') . '?id=' . (int)$payment['student_id']
    : base_url($role . '/payments.php?tab=ledger');
?>
<!DOCTYPE html>
<html lang="<?= i18n_html_lang() ?>" dir="<?= i18n_dir() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt #<?= (int)$payment['receipt_no'] ?></title>
<link href="<?= $assetBase ?>/vendor/bootstrap/<?= $bootstrapCss ?>?v=5.3.3" rel="stylesheet">
<link href="<?= $assetBase ?>/vendor/bootstrap-icons/bootstrap-icons.min.css?v=1.11.3" rel="stylesheet">
<link href="<?= $assetBase ?>/vendor/fonts/fonts.css?v=1" rel="stylesheet">
<style>
  :root { --brand: #4f46e5; --ink: #111827; --muted: #6b7280; --line: #e5e7eb; }
  body { font-family: 'Inter', 'Noto Sans Arabic', -apple-system, BlinkMacSystemFont, sans-serif; background: #eef0f6; color: var(--ink); margin: 0; padding: 24px 12px 60px; }
  .toolbar { max-width: 640px; margin: 0 auto 16px; display: flex; gap: 8px; justify-content: space-between; }
  .receipt { position: relative; max-width: 640px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 36px 40px; box-shadow: 0 10px 30px rgba(17,24,39,.10); overflow: hidden; }
  .receipt::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 6px; background: var(--brand); }
  .r-head { display: flex; align-items: center; gap: 16px; padding-bottom: 18px; border-bottom: 1.5px solid var(--ink); }
  .r-logo { width: 78px; height: 78px; object-fit: contain; flex-shrink: 0; }
  .r-logo-fallback { display: flex; align-items: center; justify-content: center; border-radius: 18px; background: var(--brand); color: #fff; font-size: 2rem; font-weight: 800; }
  .r-inst { font-size: 1.25rem; font-weight: 700; line-height: 1.25; }
  .r-kind { font-size: .72rem; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); margin-top: 2px; }
  .r-no { margin-inline-start: auto; text-align: end; }
  .r-no .n { font-size: 1.4rem; font-weight: 800; color: var(--brand); }
  .r-amount { margin: 26px 0 8px; padding: 18px 20px; border-radius: 12px; background: #f1f5ff; display: flex; justify-content: space-between; align-items: center; }
  .r-amount .label { font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
  .r-amount .value { font-size: 2rem; font-weight: 800; }
  dl.r-grid { display: grid; grid-template-columns: 150px 1fr; row-gap: 10px; margin: 18px 0; }
  dl.r-grid dt { color: var(--muted); font-weight: 500; font-size: .9rem; }
  dl.r-grid dd { margin: 0; font-weight: 600; }
  [dir=rtl] .r-void { letter-spacing: 0; }
  [dir=rtl] .r-kind { letter-spacing: 0; }
  .r-sum { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; margin-top: 8px; }
  .r-sum div { display: flex; justify-content: space-between; padding: 10px 16px; }
  .r-sum div + div { border-top: 1px solid var(--line); }
  .r-sum .bal { background: #fafafa; font-weight: 800; }
  .r-sign { display: flex; justify-content: space-between; gap: 40px; margin-top: 54px; }
  .r-sign div { flex: 1; border-top: 1px solid var(--ink); padding-top: 6px; text-align: center; font-size: .8rem; color: var(--muted); }
  .r-foot { margin-top: 22px; text-align: center; font-size: .75rem; color: var(--muted); }
  .r-void { position: absolute; top: 46%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 5rem; font-weight: 900; letter-spacing: .1em; color: rgba(220, 38, 38, .22); border: 8px solid rgba(220, 38, 38, .22); padding: 0 24px; border-radius: 16px; pointer-events: none; }
  @page { size: A5 portrait; margin: 8mm; }
  @media print {
    body { background: #fff; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .toolbar { display: none; }
    .receipt { box-shadow: none; border-radius: 0; max-width: none; padding: 8mm 6mm; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <a href="<?= h($backUrl) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print receipt</button>
</div>

<div class="receipt">
  <?php if ($void): ?><div class="r-void">VOID</div><?php endif; ?>
  <div class="r-head">
    <?php if ($logo): ?>
      <img class="r-logo" src="<?= $logo ?>" alt="">
    <?php else: ?>
      <div class="r-logo r-logo-fallback"><?= h(mb_strtoupper(mb_substr(trim($instituteName), 0, 1)) ?: '•') ?></div>
    <?php endif; ?>
    <div>
      <div class="r-inst" dir="auto"><?= h($instituteName) ?></div>
      <div class="r-kind">Payment receipt</div>
    </div>
    <div class="r-no">
      <div class="small text-muted">Receipt no.</div>
      <div class="n">#<?= (int)$payment['receipt_no'] ?></div>
    </div>
  </div>

  <div class="r-amount">
    <div>
      <div class="label">Amount received</div>
      <div class="value"><?= format_money_auto((float)$payment['amount']) ?></div>
    </div>
    <i class="bi bi-patch-check-fill" style="font-size:2.4rem;color:<?= $void ? '#dc2626' : '#16a34a' ?>"></i>
  </div>

  <dl class="r-grid">
    <dt>Received from</dt><dd dir="auto"><?= h($student ? student_full_name($student) : $payment['student_name']) ?></dd>
    <?php if ($student): ?>
      <dt>Class</dt><dd><?= h($student['department_name']) ?> · Grade <?= h($payment['grade']) ?><?= $payment['grade'] === $student['grade'] ? ' / ' . h($student['class_group']) : '' ?></dd>
    <?php else: ?>
      <dt>Grade</dt><dd>Grade <?= h($payment['grade']) ?></dd>
    <?php endif; ?>
    <dt>Date</dt><dd><?= h(format_pay_date($payment['paid_at'])) ?></dd>
    <dt>Method</dt><dd><?= h(t(PAYMENT_METHODS[$payment['method']] ?? $payment['method'])) ?><?= $payment['reference'] ? ' · ' . h($payment['reference']) : '' ?></dd>
    <?php if ($payment['note']): ?><dt>Note</dt><dd><?= h($payment['note']) ?></dd><?php endif; ?>
    <dt>Received by</dt><dd><?= h($payment['recorded_by_name']) ?></dd>
  </dl>

  <?php if ($fee && $fee['priced'] && !$void): ?>
    <div class="r-sum">
      <div><span>Grade <?= h($payment['grade']) ?> fee<?= $fee['discount_amount'] > 0 ? ' (after discount)' : '' ?></span><strong><?= format_money_auto($fee['fee']) ?></strong></div>
      <div><span>Total paid up to this receipt</span><strong class="text-success"><?= format_money_auto($paidToDate) ?></strong></div>
      <?php $bal = round($fee['fee'] - $paidToDate, 2); ?>
      <div class="bal"><span>Balance remaining</span><span class="<?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= $bal > 0 ? format_money_auto($bal) : 'Paid in full' ?></span></div>
    </div>
  <?php endif; ?>

  <?php if ($void): ?>
    <div class="alert alert-danger mt-3 mb-0 py-2 small">This payment was voided on <?= h(format_pay_date(substr((string)$payment['voided_at'], 0, 10))) ?> — <?= h($payment['void_reason']) ?>. It is not counted toward any balance.</div>
  <?php endif; ?>

  <div class="r-sign"><div>Received by</div><div>Parent / student</div></div>
  <div class="r-foot">Printed <?= h(date('d M Y, H:i')) ?> · Keep this receipt as proof of payment.</div>
</div>
</body>
</html>
