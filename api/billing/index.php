<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/partner-auth.php';
require_once dirname(__DIR__, 2) . '/partner-billing-storage.php';
require_once dirname(__DIR__, 2) . '/partner-preference-storage.php';

jg_partner_require_auth_json();

function jg_partner_billing_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function jg_partner_billing_request(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data')) {
        return $_POST;
    }
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function jg_partner_billing_localized_error(string $message, string $language): string
{
    if ($language !== 'id') {
        return $message;
    }
    return [
        'Choose a PDF or image file.' => 'Pilih file PDF atau gambar.',
        'The selected file could not be read.' => 'File yang dipilih tidak dapat dibaca.',
        'The file must be 10 MB or smaller.' => 'Ukuran file harus 10 MB atau kurang.',
        'Only PDF, PNG, JPG, GIF, or WebP files are accepted.' => 'Hanya file PDF, PNG, JPG, GIF, atau WebP yang diterima.',
        'The selected image is not valid.' => 'Gambar yang dipilih tidak valid.',
        'Bill not found.' => 'Tagihan tidak ditemukan.',
        'This billing period is still open.' => 'Periode tagihan ini masih berjalan.',
        'This bill is already paid.' => 'Tagihan ini sudah lunas.',
        'Wait for the dispute review before paying this bill.' => 'Tunggu hasil tinjauan sengketa sebelum membayar tagihan ini.',
        'This bill has no balance due.' => 'Tagihan ini tidak memiliki saldo terutang.',
        'Describe why these orders were already paid.' => 'Jelaskan mengapa pesanan ini sudah dibayar.',
        'Select at least one order to dispute.' => 'Pilih setidaknya satu pesanan untuk disengketakan.',
        'Too many orders were selected.' => 'Terlalu banyak pesanan yang dipilih.',
        'This bill cannot be disputed in its current state.' => 'Tagihan ini tidak dapat disengketakan dalam status saat ini.',
        'One or more selected orders are no longer available for dispute.' => 'Satu atau beberapa pesanan yang dipilih tidak lagi dapat disengketakan.',
        'Enter a proposed price for every product in each selected order.' => 'Masukkan harga usulan untuk setiap produk dalam setiap pesanan yang dipilih.',
        'Each proposed product price must be between Rp 0 and Rp 1,000,000,000,000.' => 'Setiap harga produk yang diusulkan harus antara Rp 0 dan Rp 1.000.000.000.000.',
        'File not found.' => 'File tidak ditemukan.',
        'Method not allowed.' => 'Metode tidak diizinkan.',
        'Unknown billing action.' => 'Tindakan tagihan tidak dikenali.',
        'Billing is temporarily unavailable.' => 'Tagihan sementara tidak tersedia.',
        'Billing reconciliation did not pass its integrity checks.' => 'Rekonsiliasi tagihan tidak lulus pemeriksaan integritas.',
        'A late order reached a billing period with an active payment or dispute.' => 'Pesanan terlambat masuk ke periode tagihan yang memiliki pembayaran atau sengketa aktif.',
    ][$message] ?? $message;
}

$partnerCode = jg_partner_current_code();
$partner = jg_partner_current_profile();
$billingLanguage = 'id';
try {
    $billingLanguage = (string) (jg_partner_preferences($partnerCode)['language'] ?? 'id');
} catch (Throwable) {
    // Keep the established Indonesian default when preferences are unavailable.
}
$partnerSlug = jg_partner_profile_slug($partner);
$endpoint = ($partnerSlug !== '' ? '/' . rawurlencode($partnerSlug) : '') . '/api/billing/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

try {
    if ($method === 'GET' && $action === 'file') {
        $fileId = (int) ($_GET['id'] ?? 0);
        if ($fileId <= 0) {
            throw new InvalidArgumentException('File not found.');
        }
        jg_partner_billing_stream_file($partnerCode, $fileId);
    }

    if ($method === 'GET') {
        jg_partner_billing_json(jg_partner_billing_payload($partnerCode, $endpoint));
    }

    if ($method !== 'POST') {
        jg_partner_billing_json(['ok' => false, 'error' => jg_partner_billing_localized_error('Method not allowed.', $billingLanguage)], 405);
    }

    jg_partner_require_csrf_json();
    $request = jg_partner_billing_request();
    $action = strtolower(trim((string) ($request['action'] ?? $action)));

    if ($action === 'mark_seen' || $action === 'complete_tutorial') {
        jg_partner_billing_json([
            'ok' => true,
            'onboarding' => jg_partner_billing_mark_onboarding($partnerCode, $action),
        ]);
    }

    if ($action === 'submit_payment') {
        $billId = trim((string) ($request['bill_id'] ?? ''));
        if (!isset($_FILES['proof']) || !is_array($_FILES['proof'])) {
            throw new InvalidArgumentException('Choose a PDF or image file.');
        }
        jg_partner_billing_submit_payment($partnerCode, $billId, $_FILES['proof']);
        jg_partner_billing_json(jg_partner_billing_payload($partnerCode, $endpoint), 201);
    }

    if ($action === 'submit_dispute') {
        $orderIds = $request['order_ids'] ?? [];
        if (is_string($orderIds)) {
            $decoded = json_decode($orderIds, true);
            $orderIds = is_array($decoded) ? $decoded : [];
        }
        jg_partner_billing_submit_dispute(
            $partnerCode,
            trim((string) ($request['bill_id'] ?? '')),
            is_array($orderIds) ? $orderIds : [],
            (string) ($request['reason'] ?? ''),
            $request['price_proposals'] ?? []
        );
        jg_partner_billing_json(jg_partner_billing_payload($partnerCode, $endpoint), 201);
    }

    jg_partner_billing_json(['ok' => false, 'error' => jg_partner_billing_localized_error('Unknown billing action.', $billingLanguage)], 400);
} catch (InvalidArgumentException $error) {
    jg_partner_billing_json(['ok' => false, 'error' => jg_partner_billing_localized_error($error->getMessage(), $billingLanguage)], 422);
} catch (RuntimeException $error) {
    jg_partner_billing_json(['ok' => false, 'error' => jg_partner_billing_localized_error($error->getMessage(), $billingLanguage)], 503);
} catch (Throwable $error) {
    error_log('Partner billing API failed: ' . $error->getMessage());
    jg_partner_billing_json(['ok' => false, 'error' => jg_partner_billing_localized_error('Billing is temporarily unavailable.', $billingLanguage)], 500);
}
