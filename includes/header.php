<?php
/**
 * Expects $pageTitle to be set before include.
 * Uses a base-relative asset path so it works whether included from
 * /admin, /teacher, /student, or the project root.
 */
$assetBase = base_url('assets');

// Cache-busting: append the file's last-modified time as a version string,
// so browsers automatically fetch the new CSS/JS after any update instead
// of serving a stale cached copy. No manual "hard refresh" ever needed.
$cssFile = __DIR__ . '/../assets/css/style.css';
$jsFile = __DIR__ . '/../assets/js/app.js';
$cssVersion = is_file($cssFile) ? filemtime($cssFile) : time();
$jsVersion = is_file($jsFile) ? filemtime($jsFile) : time();
i18n_start(); // Kurdish / Arabic: translate this page as it is sent
$bootstrapCss = i18n_is_rtl() ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= i18n_html_lang() ?>" dir="<?= i18n_dir() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= h($pageTitle ?? 'Institute System') ?></title>

<!-- PWA / "Add to Home Screen" support - lets the app install and run
     full-screen like a native app on phones and tablets. -->
<link rel="manifest" href="<?= base_url('manifest.php') ?>">
<meta name="theme-color" content="#4f46e5">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Institute">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $assetBase ?>/icons/icon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="<?= $assetBase ?>/icons/icon-192.png">
<link rel="shortcut icon" href="<?= $assetBase ?>/icons/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $assetBase ?>/icons/icon-180.png">
<link rel="apple-touch-icon" sizes="167x167" href="<?= $assetBase ?>/icons/icon-167.png">
<link rel="apple-touch-icon" sizes="152x152" href="<?= $assetBase ?>/icons/icon-152.png">

<!-- Bootstrap, icons and fonts are served from this site (assets/vendor), not a CDN —
     so the UI never breaks if a third-party host is slow, blocked, or down. -->
<link href="<?= $assetBase ?>/vendor/bootstrap/<?= $bootstrapCss ?>?v=5.3.3" rel="stylesheet">
<link href="<?= $assetBase ?>/vendor/bootstrap-icons/bootstrap-icons.min.css?v=1.11.3" rel="stylesheet">
<link href="<?= $assetBase ?>/vendor/fonts/fonts.css?v=1" rel="stylesheet">
<link href="<?= $assetBase ?>/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet">
</head>
<body class="lang-<?= i18n_lang() ?>">
