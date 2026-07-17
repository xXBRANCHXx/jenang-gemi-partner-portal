<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-favicon-storage.php';

function jg_partner_favicon_fail(string $message, int $status = 422): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

jg_partner_require_auth_json();

$partnerCode = jg_partner_current_code();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$partnerSlug = jg_partner_profile_slug(jg_partner_current_profile());
$endpoint = ($partnerSlug !== '' ? '/' . rawurlencode($partnerSlug) : '') . '/api/favicon/';

if ($method === 'GET' && trim((string) ($_GET['theme'] ?? '')) !== '') {
    try {
        jg_partner_favicon_stream($partnerCode, (string) $_GET['theme']);
    } catch (InvalidArgumentException $exception) {
        jg_partner_favicon_fail($exception->getMessage(), 422);
    } catch (Throwable $exception) {
        jg_partner_favicon_fail($exception->getMessage() ?: 'Favicon is unavailable.', 404);
    }
}

if ($method === 'GET') {
    try {
        echo json_encode([
            'ok' => true,
            'favicons' => jg_partner_favicon_public_settings($partnerCode, $endpoint),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $exception) {
        jg_partner_favicon_fail($exception->getMessage() ?: 'Unable to load favicon settings.', 500);
    }
}

jg_partner_require_csrf_json();

try {
    if ($method === 'POST') {
        $theme = (string) ($_POST['theme'] ?? '');
        if (!isset($_FILES['favicon']) || !is_array($_FILES['favicon'])) {
            jg_partner_favicon_fail('Choose a PNG or ICO favicon.');
        }
        jg_partner_favicon_save($partnerCode, $theme, $_FILES['favicon']);
    } elseif ($method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $theme = (string) (is_array($payload) ? ($payload['theme'] ?? '') : '');
        jg_partner_favicon_delete($partnerCode, $theme);
    } else {
        jg_partner_favicon_fail('Method not allowed.', 405);
    }

    echo json_encode([
        'ok' => true,
        'favicons' => jg_partner_favicon_public_settings($partnerCode, $endpoint),
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    jg_partner_favicon_fail($exception->getMessage(), 422);
} catch (Throwable $exception) {
    jg_partner_favicon_fail($exception->getMessage() ?: 'Unable to update favicon.', 500);
}
