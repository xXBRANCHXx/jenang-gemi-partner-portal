<?php
declare(strict_types=1);

require __DIR__ . '/partner-source.php';

const JG_PARTNER_SESSION_LIFETIME = 2592000;

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
    $code = jg_partner_current_code();
    if ($code === '') {
        return null;
    }
    return jg_partner_source_find($code);
}

function jg_partner_attempt_login(string $code): bool
{
    jg_partner_start_session();
    $partner = jg_partner_source_find(strtoupper(trim($code)));
    if (!$partner) {
        return false;
    }

    $partnerName = trim((string) ($partner['name'] ?? ''));
    session_regenerate_id(true);
    $_SESSION['jg_partner_code'] = (string) ($partner['code'] ?? '');
    $_SESSION['jg_partner_name'] = $partnerName;
    $_SESSION['jg_partner_login_at'] = gmdate(DATE_ATOM);
    return true;
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
