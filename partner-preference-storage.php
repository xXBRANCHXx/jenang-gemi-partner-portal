<?php
declare(strict_types=1);

require_once __DIR__ . '/partner-data-bootstrap.php';

const JG_PARTNER_PREFERENCE_JSON_FILE = __DIR__ . '/data/partner-preferences.json';

function jg_partner_preference_defaults(): array
{
    return [
        'language' => 'id',
        'timezone' => 'Asia/Jakarta',
    ];
}

function jg_partner_preference_languages(): array
{
    return [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
}

function jg_partner_preference_timezones(): array
{
    return [
        'Asia/Jakarta' => 'WIB (UTC+7)',
        'Asia/Makassar' => 'WITA (UTC+8)',
        'Asia/Jayapura' => 'WIT (UTC+9)',
    ];
}

function jg_partner_preference_normalize(array $value): array
{
    $defaults = jg_partner_preference_defaults();
    $language = strtolower(trim((string) ($value['language'] ?? $defaults['language'])));
    $timezone = trim((string) ($value['timezone'] ?? $defaults['timezone']));
    if (!array_key_exists($language, jg_partner_preference_languages())) {
        throw new InvalidArgumentException('Language is not supported.');
    }
    if (!array_key_exists($timezone, jg_partner_preference_timezones())) {
        throw new InvalidArgumentException('Time zone is not supported.');
    }
    return ['language' => $language, 'timezone' => $timezone];
}

function jg_partner_preference_json_read(): array
{
    if (jg_partner_data_mysql_is_configured()) {
        throw new LogicException('Regional preference storage is temporarily unavailable.');
    }
    if (!is_file(JG_PARTNER_PREFERENCE_JSON_FILE)) {
        return ['preferences' => []];
    }
    $decoded = json_decode((string) @file_get_contents(JG_PARTNER_PREFERENCE_JSON_FILE), true);
    return is_array($decoded) && is_array($decoded['preferences'] ?? null)
        ? $decoded
        : ['preferences' => []];
}

function jg_partner_preference_json_write(array $database): void
{
    $encoded = json_encode($database, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || @file_put_contents(JG_PARTNER_PREFERENCE_JSON_FILE, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save regional preferences.');
    }
}

function jg_partner_preferences(string $partnerCode): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    $record = null;
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT language, timezone FROM partner_preferences WHERE partner_code = :partner_code LIMIT 1');
        $stmt->execute([':partner_code' => $partnerCode]);
        $record = $stmt->fetch();
    } else {
        $database = jg_partner_preference_json_read();
        foreach ($database['preferences'] as $candidate) {
            if (is_array($candidate) && strtoupper((string) ($candidate['partner_code'] ?? '')) === $partnerCode) {
                $record = $candidate;
                break;
            }
        }
    }

    try {
        return jg_partner_preference_normalize(is_array($record) ? $record : []);
    } catch (InvalidArgumentException) {
        return jg_partner_preference_defaults();
    }
}

function jg_partner_preferences_save(string $partnerCode, array $value): array
{
    $partnerCode = strtoupper(trim($partnerCode));
    $preferences = jg_partner_preference_normalize($value);
    $pdo = jg_partner_data_db();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare(
            'INSERT INTO partner_preferences (partner_code, language, timezone, created_at, updated_at)
             VALUES (:partner_code, :language, :timezone, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE language = VALUES(language), timezone = VALUES(timezone), updated_at = VALUES(updated_at)'
        );
        $now = jg_partner_data_now();
        $stmt->execute([
            ':partner_code' => $partnerCode,
            ':language' => $preferences['language'],
            ':timezone' => $preferences['timezone'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } else {
        $database = jg_partner_preference_json_read();
        $database['preferences'] = array_values(array_filter(
            $database['preferences'],
            static fn (mixed $candidate): bool => !is_array($candidate)
                || strtoupper((string) ($candidate['partner_code'] ?? '')) !== $partnerCode
        ));
        $database['preferences'][] = [
            'partner_code' => $partnerCode,
            ...$preferences,
            'updated_at' => gmdate(DATE_ATOM),
        ];
        jg_partner_preference_json_write($database);
    }
    return $preferences;
}
