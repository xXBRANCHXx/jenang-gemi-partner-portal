<?php
declare(strict_types=1);

function jg_partner_portal_ensure_local_config_file(): void
{
    $localConfig = __DIR__ . '/config.local.php';
    $publicConfig = '/public_html/config.local.php';
    $placeholderConfig = __DIR__ . '/config.local.placeholder.php';

    if (is_file($localConfig) || is_file($publicConfig) || !is_file($placeholderConfig)) {
        return;
    }

    if (!is_writable(__DIR__)) {
        return;
    }

    copy($placeholderConfig, $localConfig);
}

function jg_partner_portal_load_local_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    jg_partner_portal_ensure_local_config_file();

    $config = [];
    $configFiles = [
        __DIR__ . '/config.local.php',
        '/public_html/config.local.php',
    ];

    foreach ($configFiles as $configFile) {
        if (!is_file($configFile)) {
            continue;
        }

        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
        }
    }

    return $config;
}

function jg_partner_portal_env_value(string $key): string
{
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    $serverValue = $_SERVER[$key] ?? null;
    if (is_string($serverValue) && trim($serverValue) !== '') {
        return trim($serverValue);
    }

    $envValue = $_ENV[$key] ?? null;
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    return '';
}

function jg_partner_portal_config_value(string $envKey, string $configKey, string $default = ''): string
{
    $envValue = jg_partner_portal_env_value($envKey);
    if ($envValue !== '') {
        return $envValue;
    }

    $config = jg_partner_portal_load_local_config();
    $configValue = $config[$configKey] ?? null;
    if (is_string($configValue) && trim($configValue) !== '') {
        return trim($configValue);
    }

    return $default;
}
