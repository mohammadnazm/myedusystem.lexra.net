<?php
/**
 * Database connection + global runtime settings.
 *
 * Per-server settings live in config/db.local.php (copy db.local.example.php).
 * That file is never overwritten when you upload a new version of the app, so
 * the hosting credentials survive updates. Any value it doesn't set falls back
 * to the defaults below (fine for a local Laragon/XAMPP install).
 */
if (is_file(__DIR__ . '/db.local.php')) {
    require __DIR__ . '/db.local.php';
}
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'institute_system');

/**
 * 'production' (the default) hides PHP error details from visitors
 * (info-disclosure risk) while still logging full details server-side. Set
 * APP_ENV to 'development' in config/db.local.php only while debugging on a
 * local machine, and never on the live site.
 */
defined('APP_ENV') || define('APP_ENV', 'production');

/**
 * The time zone every date/time in the app is shown and stored in — used for
 * PHP *and* set on the MySQL connection below, so "Added on" times (and the
 * activity log) read the same on any server, whatever its own clock says.
 * Erbil / Iraq is UTC+3 all year (no daylight saving).
 */
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Baghdad');
date_default_timezone_set(APP_TIMEZONE);

$isProd = APP_ENV === 'production';
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Catch anything that would otherwise crash to a blank page (an uncaught
 * exception, or a fatal error like calling an undefined function). Always
 * logs full details server-side; shows them on-screen only outside
 * production, so a live site never leaks internals to a visitor.
 */
set_exception_handler(function (Throwable $e) use ($isProd) {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if ($isProd) {
        echo '<p style="font-family:sans-serif;max-width:600px;margin:60px auto;">Something went wrong on our end. Please try again in a moment, or contact your administrator if this keeps happening.</p>';
    } else {
        echo '<pre style="white-space:pre-wrap;font-family:monospace;background:#fee;color:#900;padding:20px;margin:20px;border-radius:8px;">'
            . htmlspecialchars($e->getMessage() . "\n\nin " . $e->getFile() . ' on line ' . $e->getLine() . "\n\n" . $e->getTraceAsString())
            . '</pre>';
    }
});

register_shutdown_function(function () use ($isProd) {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
    }
    if ($isProd) {
        echo '<p style="font-family:sans-serif;max-width:600px;margin:60px auto;">Something went wrong on our end. Please try again in a moment, or contact your administrator if this keeps happening.</p>';
    } else {
        echo '<pre style="white-space:pre-wrap;font-family:monospace;background:#fee;color:#900;padding:20px;margin:20px;border-radius:8px;">'
            . htmlspecialchars($error['message'] . "\n\nin " . $error['file'] . ' on line ' . $error['line'])
            . '</pre>';
    }
});

/**
 * Auto-detect the URL base path (works whether the app is at your domain
 * root, a Laragon .test virtual host, or a subfolder like localhost/institute-system).
 * No manual configuration needed.
 */
if (!defined('APP_BASE_PATH')) {
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $scriptFile = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $basePath = '';
    if ($scriptFile && str_starts_with($scriptFile, $projectRoot)) {
        $relative = substr($scriptFile, strlen($projectRoot)); // e.g. /admin/dashboard.php
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($relative !== '' && str_ends_with($scriptName, $relative)) {
            $basePath = substr($scriptName, 0, strlen($scriptName) - strlen($relative));
        }
    }
    define('APP_BASE_PATH', rtrim($basePath, '/'));
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    // TIMESTAMP columns (created_at...) are stored in UTC internally and converted using
    // this per-connection setting on the way in and out, so old rows show correctly too.
    $conn->query("SET time_zone = '" . (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P') . "'");
} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('The site is temporarily unavailable. Please try again shortly.');
}
