<?php
/**
 * Service worker. Deliberately does NOT load config/db.php - it only
 * needs the URL base path, and must keep working even if the database
 * is temporarily down (that's the whole point of a service worker).
 */
$projectRoot = str_replace('\\', '/', realpath(__DIR__));
$scriptFile = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
$basePath = '';
if ($scriptFile && str_starts_with($scriptFile, $projectRoot)) {
    $relative = substr($scriptFile, strlen($projectRoot));
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($relative !== '' && str_ends_with($scriptName, $relative)) {
        $basePath = substr($scriptName, 0, strlen($scriptName) - strlen($relative));
    }
}
$base = rtrim($basePath, '/');

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ' . $base . '/');
?>
const CACHE_NAME = 'institute-app-shell-v2';
const BASE = '<?= $base ?>';
const SHELL_ASSETS = [
  BASE + '/assets/css/style.css',
  BASE + '/assets/js/app.js',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(SHELL_ASSETS);
    }).catch(function () { /* offline on first install - fine, just skip pre-caching */ })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (names) {
      return Promise.all(names.filter(function (n) { return n !== CACHE_NAME; }).map(function (n) {
        return caches.delete(n);
      }));
    })
  );
  self.clients.claim();
});

// Only ever serve cached content for our OWN static assets (CSS/JS/icons).
// Every page itself is dynamic PHP with live data (grades, attendance,
// money) - those always go straight to the network, never cached or
// served offline, so nothing stale is ever shown.
self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  if (url.origin !== self.location.origin || SHELL_ASSETS.indexOf(url.pathname) === -1) return;

  event.respondWith(
    caches.match(req).then(function (cached) {
      return cached || fetch(req);
    })
  );
});
