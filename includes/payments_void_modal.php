<?php
/**
 * "Void this payment" modal, shared by the Payments page and the student statement.
 * Expects: $voidReturnTo ('list'|'student'), $voidReturnQs (string), $voidStudentId (int).
 * Buttons with class .js-void and data-id / data-label open it.
 */
?>
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content pay-modal">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="void_payment">
      <input type="hidden" name="return_to" value="<?= h($voidReturnTo) ?>">
      <input type="hidden" name="return_qs" value="<?= h($voidReturnQs) ?>">
      <input type="hidden" name="student_id" value="<?= (int)$voidStudentId ?>">
      <input type="hidden" name="payment_id" id="void-payment-id">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-slash-circle text-danger me-2"></i>Void payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 small mb-3">
          <div class="fw-semibold" id="void-label"></div>
          Voiding keeps the payment on record but removes it from the student's paid total and the school's totals. It can't be undone.
        </div>
        <label class="form-label">Reason</label>
        <input type="text" name="reason" id="void-reason" class="form-control" maxlength="255" required placeholder="e.g. entered the wrong amount">
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger px-4">Void payment</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('voidModal');
  if (!el) return;
  var modal = new bootstrap.Modal(el);
  document.querySelectorAll('.js-void').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('void-payment-id').value = btn.dataset.id;
      document.getElementById('void-label').textContent = btn.dataset.label;
      document.getElementById('void-reason').value = '';
      modal.show();
    });
  });
  el.addEventListener('shown.bs.modal', function () { document.getElementById('void-reason').focus(); });
});
</script>
