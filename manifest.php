<?php
/**
 * PWA manifest. Deliberately does NOT load config/db.php - this file must
 * keep working (so the app stays installable) even if the database is
 * temporarily down, and it doesn't need a DB connection for anything.
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

header('Content-Type: application/manifest+json');

$manifest = [
    'name' => 'Institute System',
    'short_name' => 'Institute',
    'description' => 'Manage students, marks, attendance, and accounting from your phone or tablet.',
    'start_url' => $base . '/index.php',
    'scope' => $base . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#f6f7fb',
    'theme_color' => '#4f46e5',
    'icons' => [
        ['src' => $base . '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $base . '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
