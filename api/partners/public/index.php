<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$path = dirname(__DIR__, 3) . '/data/partners.json';

if (!file_exists($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'Partner registry not found.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'Partner registry is invalid.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'meta' => $data['meta'] ?? [],
    'partners' => array_values(array_filter($data['partners'] ?? [], 'is_array')),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
