<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-order-storage.php';

const JG_PARTNER_DEPOSIT_MAX_BYTES = 10 * 1024 * 1024;

function jg_partner_class_b_profile(?array $partner): bool
{
    return is_array($partner) && strtoupper(trim((string) ($partner['partner_class'] ?? 'B'))) === 'B';
}

function jg_partner_class_b_require_profile(?array $partner): array
{
    if (!jg_partner_class_b_profile($partner)) {
        throw new InvalidArgumentException('This feature is available to Class B partners only.');
    }
    return $partner;
}

function jg_partner_class_b_db(): PDO
{
    $pdo = jg_partner_data_db();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Class B balance storage is temporarily unavailable.');
    }
    return $pdo;
}

function jg_partner_class_b_event(PDO $pdo, string $partnerCode, string $entityType, string $entityId, string $eventType, string $title, string $detail = '', string $actor = 'system'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO partner_stock_events
            (partner_code, entity_type, entity_id, event_type, title, detail, actor, created_at)
         VALUES (:partner_code, :entity_type, :entity_id, :event_type, :title, :detail, :actor, UTC_TIMESTAMP())'
    );
    $stmt->execute([
        ':partner_code' => strtoupper(trim($partnerCode)),
        ':entity_type' => mb_substr(trim($entityType), 0, 32),
        ':entity_id' => mb_substr(trim($entityId), 0, 80),
        ':event_type' => mb_substr(trim($eventType), 0, 48),
        ':title' => mb_substr(trim($title), 0, 180),
        ':detail' => mb_substr(trim($detail), 0, 1000),
        ':actor' => mb_substr(trim($actor), 0, 80),
    ]);
}

function jg_partner_class_b_wallet(PDO $pdo, string $partnerCode, bool $forUpdate = false): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO partner_wallets (partner_code, balance, created_at, updated_at)
         VALUES (:partner_code, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insert->execute([':partner_code' => $partnerCode]);
    $stmt = $pdo->prepare('SELECT partner_code, balance, updated_at FROM partner_wallets WHERE partner_code = :partner_code' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':partner_code' => $partnerCode]);
    $wallet = $stmt->fetch();
    if (!is_array($wallet)) throw new RuntimeException('Partner balance could not be loaded.');
    return [
        'partner_code' => (string) $wallet['partner_code'],
        'balance' => (float) $wallet['balance'],
        'updated_at' => (string) $wallet['updated_at'],
    ];
}

function jg_partner_class_b_money(mixed $value, string $label = 'Amount'): float
{
    $normalized = is_string($value) ? preg_replace('/[^0-9.\-]/', '', $value) : $value;
    if (!is_numeric($normalized)) throw new InvalidArgumentException($label . ' is invalid.');
    $amount = round((float) $normalized, 2);
    if ($amount <= 0 || $amount > 1000000000000) {
        throw new InvalidArgumentException($label . ' must be between Rp 1 and Rp 1,000,000,000,000.');
    }
    return $amount;
}

function jg_partner_class_b_deposit_file(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Upload proof of payment.');
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('The proof of payment failed to upload.');
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = trim((string) ($file['name'] ?? 'payment-proof'));
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp) || $size <= 0) throw new InvalidArgumentException('Proof of payment is empty.');
    if ($size > JG_PARTNER_DEPOSIT_MAX_BYTES) throw new InvalidArgumentException('Proof of payment must be 10 MB or smaller.');

    $mime = class_exists('finfo') ? strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp)) : strtolower((string) ($file['type'] ?? ''));
    $allowed = ['application/pdf', 'application/x-pdf', 'image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowed, true)) throw new InvalidArgumentException('Use a PDF, PNG, JPG, or WebP proof of payment.');
    $data = @file_get_contents($tmp);
    if (!is_string($data) || $data === '') throw new RuntimeException('Proof of payment could not be read.');
    return ['name' => mb_substr($name ?: 'payment-proof', 0, 255), 'mime' => $mime, 'size' => $size, 'data' => $data];
}

function jg_partner_class_b_submit_deposit(string $partnerCode, mixed $amount, array $file): array
{
    $pdo = jg_partner_class_b_db();
    $amount = jg_partner_class_b_money($amount, 'Deposit amount');
    $proof = jg_partner_class_b_deposit_file($file);
    $stmt = $pdo->prepare(
        'INSERT INTO partner_deposit_requests
            (partner_code, requested_amount, status, proof_name, proof_mime, proof_size, proof_data, submitted_at, updated_at)
         VALUES (:partner_code, :amount, "pending", :proof_name, :proof_mime, :proof_size, :proof_data, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $stmt->bindValue(':partner_code', strtoupper(trim($partnerCode)));
    $stmt->bindValue(':amount', number_format($amount, 2, '.', ''));
    $stmt->bindValue(':proof_name', $proof['name']);
    $stmt->bindValue(':proof_mime', $proof['mime']);
    $stmt->bindValue(':proof_size', $proof['size'], PDO::PARAM_INT);
    $stmt->bindValue(':proof_data', $proof['data'], PDO::PARAM_LOB);
    $stmt->execute();
    $id = (int) $pdo->lastInsertId();
    jg_partner_class_b_event($pdo, $partnerCode, 'deposit', (string) $id, 'submitted', 'Balance request submitted', 'Proof of payment sent for executive review.', 'partner');
    return jg_partner_class_b_deposit($pdo, $partnerCode, $id) ?? [];
}

function jg_partner_class_b_deposit(PDO $pdo, string $partnerCode, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, partner_code, requested_amount, approved_amount, status, proof_name, proof_mime, proof_size,
                review_note, submitted_at, reviewed_at, reviewed_by, updated_at
         FROM partner_deposit_requests WHERE id = :id AND partner_code = :partner_code LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':partner_code' => strtoupper(trim($partnerCode))]);
    $row = $stmt->fetch();
    if (!is_array($row)) return null;
    return [
        'id' => (int) $row['id'], 'partner_code' => (string) $row['partner_code'],
        'requested_amount' => (float) $row['requested_amount'],
        'approved_amount' => $row['approved_amount'] !== null ? (float) $row['approved_amount'] : null,
        'status' => (string) $row['status'], 'proof_name' => (string) $row['proof_name'],
        'proof_mime' => (string) $row['proof_mime'], 'proof_size' => (int) $row['proof_size'],
        'proof_url' => '/api/class-b/?action=proof&id=' . (int) $row['id'],
        'review_note' => (string) $row['review_note'], 'submitted_at' => (string) $row['submitted_at'],
        'reviewed_at' => (string) ($row['reviewed_at'] ?? ''), 'reviewed_by' => (string) $row['reviewed_by'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

function jg_partner_class_b_stream_proof(PDO $pdo, string $partnerCode, int $id): never
{
    $stmt = $pdo->prepare('SELECT proof_name, proof_mime, proof_data FROM partner_deposit_requests WHERE id = :id AND partner_code = :partner_code LIMIT 1');
    $stmt->execute([':id' => $id, ':partner_code' => strtoupper(trim($partnerCode))]);
    $file = $stmt->fetch();
    if (!is_array($file)) throw new RuntimeException('Proof of payment was not found.');
    $data = (string) $file['proof_data'];
    $downloadName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', (string) $file['proof_name']) ?: 'payment-proof';
    header('Content-Type: ' . (string) $file['proof_mime']);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: inline; filename="' . addcslashes($downloadName, "\"\\") . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    echo $data;
    exit;
}

function jg_partner_class_b_contact(mixed $value, string $label, int $max, bool $email = false): string
{
    $text = trim((string) $value);
    if ($text === '') throw new InvalidArgumentException($label . ' is required.');
    if (mb_strlen($text) > $max) throw new InvalidArgumentException($label . ' is too long.');
    if ($email && !filter_var($text, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    return $text;
}

function jg_partner_class_b_estimated_weight(array $items): int
{
    $grams = 0.0;
    foreach ($items as $item) {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $size = strtolower(trim((string) ($item['size'] ?? '')));
        $unitGrams = 500.0;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(kg|g|gr|gram|ml|l)\b/i', $size, $matches)) {
            $number = (float) $matches[1];
            $unit = strtolower($matches[2]);
            $unitGrams = in_array($unit, ['kg', 'l'], true) ? $number * 1000 : $number;
        }
        $grams += max(1, $unitGrams) * $quantity;
    }
    return (int) min(10000000, max(1, ceil($grams)));
}

function jg_partner_class_b_create_order(string $partnerCode, array $partner, array $payload): array
{
    jg_partner_class_b_require_profile($partner);
    $pdo = jg_partner_class_b_db();
    $payload['marketplace_platform'] = 'Class B Stock';
    $payload['customer_name'] = jg_partner_class_b_contact($partner['name'] ?? '', 'Full name', 160);
    $payload['order_timestamp'] = gmdate(DATE_ATOM);
    $payload['deadline_hours'] = 48;
    $record = jg_partner_order_build_record($partnerCode, $partner, $payload);
    $record['status'] = 'AWAITING_EXECUTIVE';
    $email = jg_partner_class_b_contact($partner['contact_email'] ?? '', 'Email address', 190, true);
    $phone = jg_partner_class_b_contact($partner['contact_phone'] ?? '', 'Phone number', 64);
    $address = jg_partner_class_b_contact($partner['contact_address'] ?? '', 'Full address', 2000);
    $weight = jg_partner_class_b_estimated_weight($record['items']);
    $total = round((float) $record['revenue_total'], 2);
    if ($total <= 0) throw new InvalidArgumentException('This order does not have a valid partner price.');

    $pdo->beginTransaction();
    try {
        $wallet = jg_partner_class_b_wallet($pdo, $partnerCode, true);
        if ((float) $wallet['balance'] < $total) throw new InvalidArgumentException('Your available balance is too low for this order.');
        jg_partner_order_insert_record_mysql($pdo, $record);
        $update = $pdo->prepare(
            'UPDATE partner_orders SET order_type = "class_b_stock", recipient_email = :email,
                    recipient_phone = :phone, recipient_address = :address, shipping_weight_grams = :weight,
                    executive_status = "awaiting_shipment", balance_amount = :amount,
                    submitted_at = UTC_TIMESTAMP(), deadline_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND partner_code = :partner_code'
        );
        $update->execute([
            ':email' => $email, ':phone' => $phone, ':address' => $address, ':weight' => $weight,
            ':amount' => number_format($total, 2, '.', ''), ':id' => $record['id'], ':partner_code' => $partnerCode,
        ]);
        $newBalance = round((float) $wallet['balance'] - $total, 2);
        $walletUpdate = $pdo->prepare('UPDATE partner_wallets SET balance = :balance, updated_at = UTC_TIMESTAMP() WHERE partner_code = :partner_code');
        $walletUpdate->execute([':balance' => number_format($newBalance, 2, '.', ''), ':partner_code' => $partnerCode]);
        $transaction = $pdo->prepare(
            'INSERT INTO partner_wallet_transactions
                (partner_code, transaction_type, amount, balance_after, reference_type, reference_id, note, actor, created_at)
             VALUES (:partner_code, "order", :amount, :balance_after, "order", :reference_id, "Class B stock order", "partner", UTC_TIMESTAMP())'
        );
        $transaction->execute([
            ':partner_code' => $partnerCode, ':amount' => number_format(-$total, 2, '.', ''),
            ':balance_after' => number_format($newBalance, 2, '.', ''), ':reference_id' => $record['id'],
        ]);
        jg_partner_class_b_event($pdo, $partnerCode, 'order', (string) $record['id'], 'submitted', 'Order submitted', 'Balance paid; waiting for the executive team to arrange shipment.', 'partner');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return jg_partner_class_b_order($pdo, $partnerCode, (string) $record['id']) ?? [];
}

function jg_partner_class_b_order(PDO $pdo, string $partnerCode, string $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.partner_code, o.customer_name, o.items_json, o.quantity, o.notes, o.status, o.revenue_total,
                o.recipient_email, o.recipient_phone, o.recipient_address, o.shipping_weight_grams,
                o.executive_status, o.balance_amount, o.submitted_at, o.shipment_arranged_at, o.created_at, o.updated_at,
                l.id AS label_id, l.original_name AS label_name, l.mime_type AS label_mime, l.size_bytes AS label_size, l.created_at AS label_created_at
         FROM partner_orders o
         LEFT JOIN partner_order_labels l ON l.order_id = o.id AND l.deleted_at IS NULL
         WHERE o.id = :id AND o.partner_code = :partner_code AND o.order_type = "class_b_stock"
         ORDER BY l.created_at DESC LIMIT 1'
    );
    $stmt->execute([':id' => $orderId, ':partner_code' => strtoupper(trim($partnerCode))]);
    $row = $stmt->fetch();
    if (!is_array($row)) return null;
    $items = json_decode((string) $row['items_json'], true);
    return [
        'id' => (string) $row['id'], 'partner_code' => (string) $row['partner_code'],
        'recipient_name' => (string) $row['customer_name'], 'recipient_email' => (string) $row['recipient_email'],
        'recipient_phone' => (string) $row['recipient_phone'], 'recipient_address' => (string) $row['recipient_address'],
        'shipping_weight_grams' => (int) $row['shipping_weight_grams'],
        'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
        'quantity' => (int) $row['quantity'], 'notes' => (string) $row['notes'],
        'total' => (float) $row['balance_amount'], 'status' => (string) $row['status'],
        'executive_status' => (string) $row['executive_status'],
        'submitted_at' => (string) ($row['submitted_at'] ?? $row['created_at']),
        'shipment_arranged_at' => (string) ($row['shipment_arranged_at'] ?? ''),
        'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        'label' => (int) ($row['label_id'] ?? 0) > 0 ? [
            'id' => (int) $row['label_id'], 'name' => (string) $row['label_name'],
            'mime_type' => (string) $row['label_mime'], 'size_bytes' => (int) $row['label_size'],
            'created_at' => (string) $row['label_created_at'],
            'url' => '/api/label-file/?order_id=' . rawurlencode((string) $row['id']),
        ] : null,
    ];
}

function jg_partner_class_b_summary(string $partnerCode): array
{
    $pdo = jg_partner_class_b_db();
    $partnerCode = strtoupper(trim($partnerCode));
    $wallet = jg_partner_class_b_wallet($pdo, $partnerCode);
    $depositStmt = $pdo->prepare(
        'SELECT id FROM partner_deposit_requests WHERE partner_code = :partner_code ORDER BY submitted_at DESC LIMIT 100'
    );
    $depositStmt->execute([':partner_code' => $partnerCode]);
    $deposits = [];
    foreach ($depositStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $deposit = jg_partner_class_b_deposit($pdo, $partnerCode, (int) $id);
        if ($deposit) $deposits[] = $deposit;
    }
    $orderStmt = $pdo->prepare('SELECT id FROM partner_orders WHERE partner_code = :partner_code AND order_type = "class_b_stock" ORDER BY created_at DESC LIMIT 100');
    $orderStmt->execute([':partner_code' => $partnerCode]);
    $orders = [];
    foreach ($orderStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $order = jg_partner_class_b_order($pdo, $partnerCode, (string) $id);
        if ($order) $orders[] = $order;
    }
    $transactionStmt = $pdo->prepare(
        'SELECT id, transaction_type, amount, balance_after, reference_type, reference_id, note, actor, created_at
         FROM partner_wallet_transactions WHERE partner_code = :partner_code ORDER BY created_at DESC, id DESC LIMIT 100'
    );
    $transactionStmt->execute([':partner_code' => $partnerCode]);
    $transactions = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'], 'type' => (string) $row['transaction_type'], 'amount' => (float) $row['amount'],
        'balance_after' => (float) $row['balance_after'], 'reference_type' => (string) $row['reference_type'],
        'reference_id' => (string) $row['reference_id'], 'note' => (string) $row['note'],
        'actor' => (string) $row['actor'], 'created_at' => (string) $row['created_at'],
    ], $transactionStmt->fetchAll());
    return ['wallet' => $wallet, 'deposits' => $deposits, 'orders' => $orders, 'transactions' => $transactions];
}
