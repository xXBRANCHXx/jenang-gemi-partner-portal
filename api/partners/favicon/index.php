<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/partner-favicon-storage.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$partnerCode = strtoupper(trim((string) ($_GET['partner_code'] ?? '')));
$theme = strtolower(trim((string) ($_GET['theme'] ?? '')));

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($partnerCode === '' || !preg_match('/^[A-Z0-9-]{3,64}$/', $partnerCode)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'A valid partner code is required.'], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    jg_partner_favicon_stream($partnerCode, $theme, 'public, max-age=86400, stale-while-revalidate=604800');
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    header('Location: https://admin.jenanggemi.com/assets/admin-icons/favicon-partners-' . $theme . '.svg', true, 302);
    header('Cache-Control: public, max-age=300');
}
