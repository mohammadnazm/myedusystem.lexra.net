<?php
/**
 * Session bootstrap + role-based access helpers.
 * Include this after config/db.php on every protected page.
 */

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443';

// Standard hardening headers on every page (the .htaccess repeats them for static files).
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');                        // no embedding this site in someone else's page
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=15552000');    // browsers keep using HTTPS for 180 days
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');   // never accept a session id the server didn't create
    ini_set('session.use_only_cookies', '1');  // no session ids in URLs
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function current_user_name(): ?string
{
    return $_SESSION['user_name'] ?? null;
}

/** Null for the platform owner (not scoped to any single institute). Every
 *  admin/teacher/student session carries the institute they belong to —
 *  this is the value every data query in the app filters by. */
function current_institute_id(): ?int
{
    return $_SESSION['institute_id'] ?? null;
}

function current_institute_name(): ?string
{
    return $_SESSION['institute_name'] ?? null;
}

/** Redirect to login if nobody is signed in. */
function require_login(): void
{
    if (!current_role()) {
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

/** Redirect away if the signed-in user isn't one of the allowed roles. */
function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

/** Absolute-from-root path back to the project base, regardless of subfolder depth. */
function base_url(string $path = ''): string
{
    $base = defined('APP_BASE_PATH') ? rtrim(APP_BASE_PATH, '/') : '';
    return $base . '/' . ltrim($path, '/');
}

function login_as(string $role, int $id, string $name, ?int $institute_id = null, ?string $institute_name = null): void
{
    session_regenerate_id(true);
    $_SESSION['role'] = $role;
    $_SESSION['user_id'] = $id;
    $_SESSION['user_name'] = $name;
    $_SESSION['institute_id'] = $institute_id;
    $_SESSION['institute_name'] = $institute_name;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** CSRF token helpers - every state-changing form should carry this. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}
