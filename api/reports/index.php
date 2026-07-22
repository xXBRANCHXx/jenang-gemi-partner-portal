<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-order-storage.php';
require_once dirname(__DIR__, 2) . '/partner-preference-storage.php';
require_once dirname(__DIR__, 2) . '/partner-favicon-storage.php';
require_once dirname(__DIR__, 2) . '/partner-report-pdf.php';

jg_partner_require_auth_json();

function jg_partner_report_api_fail(string $message, int $status = 422): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_partner_report_api_fail('Method not allowed.', 405);
}

$partnerCode = jg_partner_current_code();
$partner = jg_partner_current_profile();
try {
    $preferences = jg_partner_preferences($partnerCode);
} catch (Throwable) {
    $preferences = jg_partner_preference_defaults();
}
$language = ($preferences['language'] ?? 'id') === 'en' ? 'en' : 'id';
$timezoneName = (string) ($preferences['timezone'] ?? 'Asia/Jakarta');
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable) {
    $timezone = new DateTimeZone('Asia/Jakarta');
    $timezoneName = 'Asia/Jakarta';
}

$parseDate = static function (mixed $value, string $field) use ($timezone, $language): DateTimeImmutable {
    $raw = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $date->format('Y-m-d') !== $raw) {
        $message = $language === 'id'
            ? 'Pilih tanggal ' . ($field === 'start' ? 'mulai' : 'selesai') . ' yang valid.'
            : 'Choose a valid ' . $field . ' date.';
        jg_partner_report_api_fail($message);
    }
    return $date;
};

$start = $parseDate($_GET['start'] ?? '', 'start');
$selectedEnd = $parseDate($_GET['end'] ?? '', 'end');
if ($selectedEnd < $start) {
    jg_partner_report_api_fail($language === 'id' ? 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.' : 'End date must be on or after start date.');
}
if ((int) $start->diff($selectedEnd)->format('%a') > 366) {
    jg_partner_report_api_fail($language === 'id' ? 'Satu laporan dapat mencakup maksimal 367 hari.' : 'A single report can cover up to 367 days.');
}

$allowedSections = ['channels', 'products', 'orders'];
$requestedSections = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['sections'] ?? implode(',', $allowedSections))))));
$sections = array_values(array_intersect($allowedSections, $requestedSections));
$iconPath = null;
try {
    $faviconRecords = jg_partner_favicon_list($partnerCode);
    foreach (['light', 'dark'] as $theme) {
        if (!isset($faviconRecords[$theme])) continue;
        $candidate = jg_partner_favicon_file_path($faviconRecords[$theme]);
        if ($candidate !== null) {
            $iconPath = $candidate;
            break;
        }
    }
} catch (Throwable) {
    $iconPath = null;
}

try {
    $orders = jg_partner_order_list($partnerCode);
    $pdf = jg_partner_report_render(is_array($partner) ? $partner : [], $orders, [
        'language' => $language,
        'timezone' => $timezoneName,
        'start' => $start,
        'end' => $selectedEnd->modify('+1 day'),
        'sections' => $sections,
        'icon_path' => $iconPath,
    ]);
} catch (Throwable $exception) {
    error_log('Partner report generation failed: ' . $exception->getMessage());
    jg_partner_report_api_fail($language === 'id' ? 'Laporan PDF tidak dapat dibuat saat ini.' : 'The PDF report could not be generated right now.', 500);
}

$safeCode = preg_replace('/[^A-Z0-9_-]+/i', '-', $partnerCode) ?: 'partner';
$filename = strtolower($safeCode) . '-report-' . $start->format('Ymd') . '-' . $selectedEnd->format('Ymd') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo $pdf;
