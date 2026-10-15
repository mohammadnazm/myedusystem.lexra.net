    </main>
  </div>
</div>
<script src="<?= base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>?v=5.3.3"></script>
<script>window.I18N = <?= json_encode([
    'installMsg' => t('Install this app on your device for the full experience.'),
    'install' => t('Install'),
    'dismiss' => t('Dismiss'),
    'iosBefore' => t('Add this to your Home Screen: tap'),
    'iosAfter' => t('then "Add to Home Screen".'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="<?= base_url('assets/js/app.js') ?>?v=<?= $jsVersion ?? time() ?>"></script>
</body>
</html>
