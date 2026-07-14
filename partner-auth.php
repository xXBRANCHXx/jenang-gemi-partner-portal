<?php
declare(strict_types=1);

require __DIR__ . '/partner-source.php';

const JG_PARTNER_SESSION_LIFETIME = 43200;
const JG_PARTNER_SESSION_IDLE_TIMEOUT = 7200;

function jg_partner_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.gc_maxlifetime', (string) JG_PARTNER_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => JG_PARTNER_SESSION_LIFETIME,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('jg_partner_session');
    session_start();

    if (trim((string) ($_SESSION['jg_partner_code'] ?? '')) !== '') {
        $now = time();
        $loginAt = strtotime((string) ($_SESSION['jg_partner_login_at'] ?? '')) ?: $now;
        $lastActivity = (int) ($_SESSION['jg_partner_last_activity'] ?? $now);
        if ($now - $loginAt > JG_PARTNER_SESSION_LIFETIME || $now - $lastActivity > JG_PARTNER_SESSION_IDLE_TIMEOUT) {
            $_SESSION = [];
            session_regenerate_id(true);
        } elseif (!is_array($_SESSION['jg_partner_profile'] ?? null)) {
            // Sessions created before profile caching cannot safely use the narrowed public registry.
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['jg_partner_last_activity'] = $now;
        }
    }
}

function jg_partner_is_authenticated(): bool
{
    jg_partner_start_session();
    return trim((string) ($_SESSION['jg_partner_code'] ?? '')) !== '';
}

function jg_partner_current_code(): string
{
    jg_partner_start_session();
    return (string) ($_SESSION['jg_partner_code'] ?? '');
}

function jg_partner_current_profile(): ?array
{
    jg_partner_start_session();
    if (is_array($_SESSION['jg_partner_profile'] ?? null)) {
        return $_SESSION['jg_partner_profile'];
    }
    $code = jg_partner_current_code();
    if ($code === '') {
        return null;
    }
    $partner = jg_partner_source_find($code);
    if (is_array($partner)) {
        $_SESSION['jg_partner_profile'] = $partner;
    }
    return $partner;
}

function jg_partner_current_slug(): string
{
    jg_partner_start_session();
    return (string) ($_SESSION['jg_partner_slug'] ?? '');
}

function jg_partner_profile_slug(?array $partner): string
{
    return trim((string) ($partner['partner_slug'] ?? ''), '/');
}

function jg_partner_attempt_login(string $code, string $password, ?array $requestedPartner = null): bool
{
    jg_partner_start_session();
    $authResult = jg_partner_source_authenticate_result($code, $password);
    $partner = !empty($authResult['ok']) && is_array($authResult['partner'] ?? null) ? $authResult['partner'] : null;
    if (!$partner) {
        return false;
    }
    if (is_array($requestedPartner) && (string) ($partner['code'] ?? '') !== (string) ($requestedPartner['code'] ?? '')) {
        return false;
    }

    $partnerName = trim((string) ($partner['name'] ?? ''));
    session_regenerate_id(true);
    $_SESSION['jg_partner_code'] = (string) ($partner['code'] ?? '');
    $_SESSION['jg_partner_name'] = $partnerName;
    $_SESSION['jg_partner_slug'] = jg_partner_profile_slug($partner);
    $_SESSION['jg_partner_profile'] = $partner;
    $_SESSION['jg_partner_login_at'] = gmdate(DATE_ATOM);
    $_SESSION['jg_partner_last_activity'] = time();
    $_SESSION['jg_partner_csrf_token'] = bin2hex(random_bytes(32));
    if (!empty($authResult['password_reset_required']) && is_string($authResult['password_reset_token'] ?? null) && $authResult['password_reset_token'] !== '') {
        $_SESSION['jg_partner_password_reset_required'] = true;
        $_SESSION['jg_partner_password_reset_token'] = (string) $authResult['password_reset_token'];
    } else {
        unset($_SESSION['jg_partner_password_reset_required'], $_SESSION['jg_partner_password_reset_token']);
    }
    return true;
}

function jg_partner_csrf_token(): string
{
    jg_partner_start_session();
    $token = (string) ($_SESSION['jg_partner_csrf_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['jg_partner_csrf_token'] = $token;
    }
    return $token;
}

function jg_partner_require_csrf_json(): void
{
    jg_partner_require_auth_json();
    $provided = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($provided !== '' && hash_equals(jg_partner_csrf_token(), $provided)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Your session security token is invalid. Refresh the page and try again.']);
    exit;
}

function jg_partner_password_reset_required(): bool
{
    jg_partner_start_session();
    return !empty($_SESSION['jg_partner_password_reset_required']) && trim((string) ($_SESSION['jg_partner_password_reset_token'] ?? '')) !== '';
}

function jg_partner_password_reset_token(): string
{
    jg_partner_start_session();
    return (string) ($_SESSION['jg_partner_password_reset_token'] ?? '');
}

function jg_partner_clear_password_reset_session(): void
{
    jg_partner_start_session();
    unset($_SESSION['jg_partner_password_reset_required'], $_SESSION['jg_partner_password_reset_token']);
}

function jg_partner_rotate_session_security(): void
{
    jg_partner_start_session();
    session_regenerate_id(true);
    $_SESSION['jg_partner_login_at'] = gmdate(DATE_ATOM);
    $_SESSION['jg_partner_last_activity'] = time();
    $_SESSION['jg_partner_csrf_token'] = bin2hex(random_bytes(32));
}

function jg_partner_is_authenticated_for(?array $partner): bool
{
    if (!jg_partner_is_authenticated() || !is_array($partner)) {
        return false;
    }

    return jg_partner_current_code() === (string) ($partner['code'] ?? '');
}

function jg_partner_dashboard_path(?array $partner = null): string
{
    $partner = is_array($partner) ? $partner : jg_partner_current_profile();
    $slug = jg_partner_profile_slug($partner);
    return $slug !== '' ? '/' . rawurlencode($slug) . '/dashboard/' : '/';
}

function jg_partner_login_path(?array $partner = null): string
{
    $partner = is_array($partner) ? $partner : jg_partner_current_profile();
    $slug = jg_partner_profile_slug($partner);
    return $slug !== '' ? '/' . rawurlencode($slug) . '/' : '/';
}

function jg_partner_logout(): void
{
    jg_partner_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function jg_partner_require_auth_json(): void
{
    if (jg_partner_is_authenticated()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_partner_require_auth_for_json(?array $partner): void
{
    if (jg_partner_is_authenticated_for($partner)) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized for this partner workspace.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
