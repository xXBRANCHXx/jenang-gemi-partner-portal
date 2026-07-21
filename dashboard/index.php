<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-auth.php';
require_once dirname(__DIR__) . '/partner-favicon-storage.php';
require_once dirname(__DIR__) . '/partner-preference-storage.php';

if (!jg_partner_is_authenticated()) {
    header('Location: /');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$dashboardJsVersion = (string) @filemtime(dirname(__DIR__) . '/dashboard.js');
$partner = jg_partner_current_profile();
$partnerSlug = jg_partner_profile_slug($partner);
$requestPath = trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');

if ($partnerSlug !== '' && $requestPath === 'dashboard') {
    header('Location: ' . jg_partner_dashboard_path($partner));
    exit;
}

$workspaceBase = $partnerSlug !== '' ? '/' . rawurlencode($partnerSlug) : '';
$sessionEndpoint = $workspaceBase . '/api/session/';
$ordersEndpoint = $workspaceBase . '/api/orders/';
$labelsEndpoint = $workspaceBase . '/api/order-labels/';
$faviconEndpoint = $workspaceBase . '/api/favicon/';
$logoutUrl = $workspaceBase . '/logout/';
$partnerName = (string) ($partner['name'] ?? 'Partner Dashboard');
$defaultFaviconUrl = 'https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png';
try {
    $faviconSettings = jg_partner_favicon_public_settings(jg_partner_current_code(), $faviconEndpoint);
} catch (Throwable) {
    $faviconSettings = [
        'light' => ['configured' => false, 'url' => '', 'name' => ''],
        'dark' => ['configured' => false, 'url' => '', 'name' => ''],
    ];
}
$lightFaviconUrl = (string) ($faviconSettings['light']['url'] ?? '') ?: $defaultFaviconUrl;
$darkFaviconUrl = (string) ($faviconSettings['dark']['url'] ?? '') ?: $defaultFaviconUrl;
try {
    $partnerPreferences = jg_partner_preferences(jg_partner_current_code());
} catch (Throwable) {
    $partnerPreferences = jg_partner_preference_defaults();
}
$configuredFaviconThemes = array_values(array_filter(
    ['light', 'dark'],
    static fn (string $theme): bool => !empty($faviconSettings[$theme]['configured'])
));
$faviconSummary = match ($configuredFaviconThemes) {
    [] => 'Using the default icon',
    ['light'] => 'Custom light icon',
    ['dark'] => 'Custom dark icon',
    default => 'Custom light and dark icons',
};
$dashboardSections = ['overview', 'orders', 'labels', 'analytics', 'settings'];
$dashboardRouteParts = $requestPath === '' ? [] : explode('/', $requestPath);
$dashboardIndex = array_search('dashboard', $dashboardRouteParts, true);
$activeSection = 'overview';
if ($dashboardIndex !== false) {
    $candidate = trim((string) ($dashboardRouteParts[$dashboardIndex + 1] ?? ''));
    if (in_array($candidate, $dashboardSections, true)) {
        $activeSection = $candidate;
    }
}
$dashboardPath = $workspaceBase . '/dashboard/';
$sectionUrl = static function (string $section) use ($dashboardPath): string {
    return $section === 'overview' ? $dashboardPath : $dashboardPath . rawurlencode($section) . '/';
};
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($partnerPreferences['language'], ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Dashboard | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" data-partner-favicon="light" media="(prefers-color-scheme: light)" href="<?php echo htmlspecialchars($lightFaviconUrl, ENT_QUOTES); ?>">
    <link rel="icon" data-partner-favicon="dark" media="(prefers-color-scheme: dark)" href="<?php echo htmlspecialchars($darkFaviconUrl, ENT_QUOTES); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="/admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.02.11</div>
    <div
        class="partner-dashboard-app partner-workspace"
        data-partner-dashboard
        data-session-endpoint="<?php echo htmlspecialchars($sessionEndpoint, ENT_QUOTES); ?>"
        data-orders-endpoint="<?php echo htmlspecialchars($ordersEndpoint, ENT_QUOTES); ?>"
        data-labels-endpoint="<?php echo htmlspecialchars($labelsEndpoint, ENT_QUOTES); ?>"
        data-favicon-endpoint="<?php echo htmlspecialchars($faviconEndpoint, ENT_QUOTES); ?>"
        data-default-favicon-url="<?php echo htmlspecialchars($defaultFaviconUrl, ENT_QUOTES); ?>"
        data-favicon-light-url="<?php echo htmlspecialchars((string) ($faviconSettings['light']['url'] ?? ''), ENT_QUOTES); ?>"
        data-favicon-light-name="<?php echo htmlspecialchars((string) ($faviconSettings['light']['name'] ?? ''), ENT_QUOTES); ?>"
        data-favicon-dark-url="<?php echo htmlspecialchars((string) ($faviconSettings['dark']['url'] ?? ''), ENT_QUOTES); ?>"
        data-favicon-dark-name="<?php echo htmlspecialchars((string) ($faviconSettings['dark']['name'] ?? ''), ENT_QUOTES); ?>"
        data-partner-language="<?php echo htmlspecialchars($partnerPreferences['language'], ENT_QUOTES); ?>"
        data-partner-timezone="<?php echo htmlspecialchars($partnerPreferences['timezone'], ENT_QUOTES); ?>"
        data-csrf-token="<?php echo htmlspecialchars(jg_partner_csrf_token(), ENT_QUOTES); ?>"
        data-logout-url="<?php echo htmlspecialchars($logoutUrl, ENT_QUOTES); ?>"
        data-dashboard-base="<?php echo htmlspecialchars($dashboardPath, ENT_QUOTES); ?>"
        data-active-section="<?php echo htmlspecialchars($activeSection, ENT_QUOTES); ?>"
        data-partner-theme="system"
    >
        <aside class="partner-sidebar">
            <div class="partner-sidebar-brand">
                <span class="partner-sidebar-kicker">Partner Workspace</span>
                <h1 data-partner-name><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></h1>
                <p data-partner-code>Direct ordering portal</p>
            </div>

            <nav class="partner-sidebar-nav" aria-label="Partner navigation">
                <a href="<?php echo htmlspecialchars($sectionUrl('overview'), ENT_QUOTES); ?>" class="<?php echo $activeSection === 'overview' ? 'is-active' : ''; ?>" data-partner-section-link="overview">Overview</a>
                <a href="<?php echo htmlspecialchars($sectionUrl('orders'), ENT_QUOTES); ?>" class="<?php echo $activeSection === 'orders' ? 'is-active' : ''; ?>" data-partner-section-link="orders">Orders</a>
                <a href="<?php echo htmlspecialchars($sectionUrl('labels'), ENT_QUOTES); ?>" class="<?php echo $activeSection === 'labels' ? 'is-active' : ''; ?>" data-partner-section-link="labels">Labels</a>
                <a href="<?php echo htmlspecialchars($sectionUrl('analytics'), ENT_QUOTES); ?>" class="<?php echo $activeSection === 'analytics' ? 'is-active' : ''; ?>" data-partner-section-link="analytics">Analytics</a>
                <a href="<?php echo htmlspecialchars($sectionUrl('settings'), ENT_QUOTES); ?>" class="<?php echo $activeSection === 'settings' ? 'is-active' : ''; ?>" data-partner-section-link="settings">Settings</a>
            </nav>

            <button type="button" class="partner-sidebar-primary" data-open-order-modal>
                <span class="partner-sidebar-primary-icon" aria-hidden="true">+</span>
                <span class="partner-sidebar-primary-label">New Order</span>
            </button>

            <div class="partner-sidebar-profile">
                <strong><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></strong>
                <span>Active partner</span>
            </div>

            <div class="partner-sidebar-actions">
                <button type="button" data-partner-logout>Logout</button>
            </div>
        </aside>

        <main class="partner-main">
            <header class="partner-page-head" data-section-title>
                <div>
                    <span>Partner portal</span>
                    <h2 data-page-title><?php echo htmlspecialchars(ucfirst($activeSection), ENT_QUOTES); ?></h2>
                </div>
                <button type="button" class="admin-primary-btn" data-open-order-modal>Create Order</button>
            </header>

            <section class="partner-section <?php echo $activeSection === 'overview' ? 'is-active' : ''; ?>" data-partner-section="overview">
                <section class="partner-metric-grid">
                    <article><span>Units sold</span><strong data-metric-units>0</strong><small data-metric-window>Last 30 days</small></article>
                    <article><span>Orders created</span><strong data-metric-orders>0</strong><small data-metric-window>Last 30 days</small></article>
                    <article><span>Avg. units/order</span><strong data-metric-average>0.0</strong><small data-metric-window>Last 30 days</small></article>
                    <article><span>Partner cost</span><strong data-metric-revenue>Rp0</strong><small data-metric-window>Last 30 days</small></article>
                </section>

                <section class="partner-overview-grid">
                    <article class="partner-panel partner-chart-panel">
                        <div class="partner-panel-head">
                            <div>
                                <span>Sales window</span>
                                <h3 data-sales-chart-title>Units sold by timeframe</h3>
                            </div>
                        </div>
                        <div class="partner-chart-range-controls">
                            <div class="partner-timeframe-toggle" data-timeframe-toggle>
                                <button type="button" data-timeframe="24h">24H</button>
                                <button type="button" data-timeframe="7d">7D</button>
                                <button type="button" data-timeframe="30d">30D</button>
                                <button type="button" data-timeframe="90d">90D</button>
                                <button type="button" data-timeframe="year">Year</button>
                                <button type="button" data-timeframe="all">All</button>
                            </div>
                            <label class="partner-month-picker" data-month-picker>
                                <span>Month</span>
                                <input type="month" data-chart-month aria-label="Choose chart month">
                            </label>
                        </div>
                        <div class="partner-chart-visual">
                            <div class="partner-chart-breakdown" data-sales-chart-breakdown aria-live="polite" hidden></div>
                            <div class="partner-bars" data-sales-chart></div>
                        </div>
                        <div class="partner-chart-legend" data-sales-chart-legend aria-label="Platform colors"></div>
                    </article>

                    <article class="partner-panel">
                        <div class="partner-panel-head">
                            <div>
                                <span>Recent orders</span>
                                <h3>Last 7 days</h3>
                            </div>
                        </div>
                        <div class="partner-recent-list" data-recent-orders></div>
                    </article>
                </section>
            </section>

            <section class="partner-section <?php echo $activeSection === 'orders' ? 'is-active' : ''; ?>" data-partner-section="orders">
                <section class="partner-panel partner-orders-panel">
                    <div class="partner-panel-head">
                        <div>
                            <span>Orders</span>
                            <h3>Order history</h3>
                        </div>
                        <button type="button" class="admin-primary-btn" data-open-order-modal>New Order</button>
                    </div>
                    <p class="admin-form-error" data-order-error hidden></p>
                    <div class="partner-order-card-list" data-order-list>
                        <p class="admin-empty">No orders yet.</p>
                    </div>
                </section>
            </section>

            <section class="partner-section <?php echo $activeSection === 'labels' ? 'is-active' : ''; ?>" data-partner-section="labels">
                <section class="partner-panel">
                    <div class="partner-panel-head">
                        <div>
                            <span>Labels</span>
                            <h3>Temporary shipping labels</h3>
                        </div>
                        <button type="button" class="admin-primary-btn" data-open-order-modal>Upload Label</button>
                    </div>
                    <p class="admin-empty">Labels are kept for up to seven days, shortened to three days after fulfillment and one day after cancellation.</p>
                    <div class="partner-label-library" data-label-library>
                        <p class="admin-empty">No labels uploaded yet.</p>
                    </div>
                </section>
            </section>

            <section class="partner-section <?php echo $activeSection === 'analytics' ? 'is-active' : ''; ?>" data-partner-section="analytics">
                <section class="partner-metric-grid">
                    <article><span>Active orders</span><strong data-analytics-active>0</strong><small>Not canceled or archived</small></article>
                    <article><span>Fulfilled</span><strong data-analytics-fulfilled>0</strong><small>Completed orders</small></article>
                    <article><span>Cancel rate</span><strong data-analytics-cancel-rate>0%</strong><small>All partner orders</small></article>
                    <article><span>Cost/order</span><strong data-analytics-revenue-order>Rp0</strong><small>Average partner cost</small></article>
                </section>
                <section class="partner-panel">
                    <div class="partner-panel-head">
                        <div>
                            <span>Reseller performance</span>
                            <h3>Platform metrics</h3>
                        </div>
                    </div>
                    <div class="partner-platform-metrics" data-platform-metrics>
                        <p class="admin-empty">Platform metrics will appear after orders are created.</p>
                    </div>
                </section>
                <section class="partner-panel">
                    <div class="partner-panel-head">
                        <div>
                            <span>Product mix</span>
                            <h3>Product units</h3>
                        </div>
                    </div>
                    <div class="partner-product-mix" data-product-mix>
                        <p class="admin-empty">No product data yet.</p>
                    </div>
                </section>
            </section>

            <section class="partner-section <?php echo $activeSection === 'settings' ? 'is-active' : ''; ?>" data-partner-section="settings">
                <div class="partner-settings-layout">
                    <section class="partner-settings-group" aria-labelledby="appearance-settings-title">
                        <header class="partner-settings-group-head">
                            <span>Personalization</span>
                            <h3 id="appearance-settings-title">Appearance</h3>
                            <p>Control how your workspace and browser tab look.</p>
                        </header>
                        <div class="partner-settings-list">
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Theme</strong>
                                    <span>Follow your device or keep this workspace light or dark.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <div class="partner-theme-switch is-inline" data-theme-switch aria-label="Theme preference">
                                        <button type="button" data-theme-option="system">System</button>
                                        <button type="button" data-theme-option="light">Light</button>
                                        <button type="button" data-theme-option="dark">Dark</button>
                                    </div>
                                </div>
                            </article>
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Browser favicon</strong>
                                    <span>Use separate icons that stay clear in light and dark mode.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <div class="partner-favicon-summary-visual" aria-hidden="true">
                                        <?php foreach (['light' => 'L', 'dark' => 'D'] as $faviconTheme => $faviconInitial): ?>
                                            <?php $favicon = $faviconSettings[$faviconTheme] ?? ['configured' => false, 'url' => '']; ?>
                                            <span class="partner-favicon-summary-preview <?php echo !empty($favicon['configured']) ? 'is-configured' : ''; ?>" data-favicon-summary-preview="<?php echo $faviconTheme; ?>">
                                                <img <?php if (!empty($favicon['configured'])): ?>src="<?php echo htmlspecialchars((string) ($favicon['url'] ?? ''), ENT_QUOTES); ?>"<?php endif; ?> alt="" <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>>
                                                <b <?php echo !empty($favicon['configured']) ? 'hidden' : ''; ?>><?php echo $faviconInitial; ?></b>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="partner-settings-status" data-favicon-summary><?php echo htmlspecialchars($faviconSummary, ENT_QUOTES); ?></span>
                                    <button type="button" class="admin-ghost-btn" data-open-favicon-modal>Manage</button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="partner-settings-group" aria-labelledby="regional-settings-title">
                        <header class="partner-settings-group-head">
                            <span>Regional</span>
                            <h3 id="regional-settings-title">Language &amp; time</h3>
                            <p>Set the language and local time used throughout this workspace.</p>
                        </header>
                        <form class="partner-settings-list" data-regional-settings-form>
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Language</strong>
                                    <span>Changes interface text and regional number formatting.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <select name="language" class="partner-settings-select" data-language-setting aria-label="Language">
                                        <?php foreach (jg_partner_preference_languages() as $languageCode => $languageLabel): ?>
                                            <option value="<?php echo htmlspecialchars($languageCode, ENT_QUOTES); ?>" <?php echo $partnerPreferences['language'] === $languageCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($languageLabel, ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </article>
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Time zone</strong>
                                    <span>Controls the time shown for orders, labels, and reporting.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <select name="timezone" class="partner-settings-select" data-timezone-setting aria-label="Time zone">
                                        <?php foreach (jg_partner_preference_timezones() as $timezoneCode => $timezoneLabel): ?>
                                            <option value="<?php echo htmlspecialchars($timezoneCode, ENT_QUOTES); ?>" <?php echo $partnerPreferences['timezone'] === $timezoneCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($timezoneLabel, ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </article>
                            <p class="partner-settings-save-status" data-regional-settings-status aria-live="polite"></p>
                        </form>
                    </section>

                    <section class="partner-settings-group" aria-labelledby="security-settings-title">
                        <header class="partner-settings-group-head">
                            <span>Account</span>
                            <h3 id="security-settings-title">Security</h3>
                            <p>Keep access to this partner workspace protected.</p>
                        </header>
                        <div class="partner-settings-list">
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Password</strong>
                                    <span>Choose a strong password you do not use elsewhere.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <button type="button" class="admin-ghost-btn" data-open-password-modal>Change password</button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="partner-settings-group" aria-labelledby="workflow-settings-title">
                        <header class="partner-settings-group-head">
                            <span>Orders</span>
                            <h3 id="workflow-settings-title">Order workflow</h3>
                            <p>Choose which sales channels are available when creating orders.</p>
                        </header>
                        <div class="partner-settings-list">
                            <article class="partner-settings-item">
                                <div class="partner-settings-item-copy">
                                    <strong>Platform options</strong>
                                    <span>Built-in marketplaces and your custom reseller profiles.</span>
                                </div>
                                <div class="partner-settings-item-control">
                                    <span class="partner-settings-status" data-platform-settings-summary>Loading options…</span>
                                    <button type="button" class="admin-ghost-btn" data-open-platform-settings-modal>Manage</button>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <div class="admin-modal-shell partner-order-modal-shell" data-order-modal hidden>
        <div class="admin-modal-backdrop" data-close-order-modal></div>
        <form class="admin-modal-card partner-order-modal partner-label-form" data-order-form role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
            <section class="partner-label-workbench">
                <div class="partner-label-main">
                    <div class="admin-modal-head">
                        <div>
                            <span class="admin-panel-kicker">New order</span>
                            <h3 id="order-modal-title">Upload label, then choose approved SKUs</h3>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-close-order-modal>Close</button>
                    </div>

                    <div class="partner-label-controls">
                        <label class="partner-label-field">
                            <span>Customer name</span>
                            <input type="text" name="customer_name" maxlength="160" placeholder="Optional customer name" data-customer-name>
                        </label>
                        <label class="partner-label-field">
                            <span>Creation time</span>
                            <input type="datetime-local" name="order_timestamp" data-order-timestamp required>
                        </label>
                        <div class="partner-label-field partner-platform-field" data-platform-picker>
                            <span>Platform</span>
                            <input type="hidden" name="marketplace_platform" value="" data-platform-select>
                            <button type="button" class="partner-platform-trigger" aria-haspopup="listbox" aria-expanded="false" aria-required="true" data-platform-trigger>
                                <span class="partner-platform-badge" aria-hidden="true" data-platform-trigger-badge>?</span>
                                <span class="partner-platform-trigger-copy">
                                    <strong data-platform-label>Select platform</strong>
                                    <small data-platform-caption>Required for every order</small>
                                </span>
                                <span class="partner-platform-chevron" aria-hidden="true">⌄</span>
                            </button>
                            <div class="partner-platform-menu" role="listbox" aria-label="Order platform" data-platform-menu hidden>
                                <button type="button" class="partner-platform-option" role="option" aria-selected="false" data-platform-option="Shopee" data-platform-kind="shopee" data-platform-caption="Shopee marketplace order" data-platform-badge-text="S">
                                    <span class="partner-platform-badge" data-platform-badge="shopee" aria-hidden="true">S</span>
                                    <span>
                                        <strong>Shopee</strong>
                                        <small>Shopee marketplace order</small>
                                    </span>
                                    <span class="partner-platform-check" aria-hidden="true">✓</span>
                                </button>
                                <button type="button" class="partner-platform-option" role="option" aria-selected="false" data-platform-option="TikTok/Toped" data-platform-kind="tiktok" data-platform-caption="TikTok/Toped marketplace order" data-platform-badge-text="T">
                                    <span class="partner-platform-badge" data-platform-badge="tiktok" aria-hidden="true">T</span>
                                    <span>
                                        <strong>TikTok/Toped</strong>
                                        <small>TikTok/Toped marketplace order</small>
                                    </span>
                                    <span class="partner-platform-check" aria-hidden="true">✓</span>
                                </button>
                            </div>
                        </div>
                        <label class="partner-label-field">
                            <span>Deadline</span>
                            <strong data-deadline-value>24h</strong>
                            <input type="range" name="deadline_hours" min="12" max="48" value="24" data-deadline-range>
                        </label>
                    </div>

                    <button type="button" class="partner-upload-dropzone partner-label-dropzone" data-label-dropzone>
                        <span class="partner-upload-plus" aria-hidden="true">+</span>
                        <strong data-label-dropzone-copy>Upload shipping label</strong>
                        <span>PDF shipment label · maximum 10 MB</span>
                    </button>
                    <input type="file" name="labels" data-label-input hidden accept=".pdf,application/pdf">

                    <div class="partner-upload-queue" data-label-queue>
                        <p class="admin-empty">No label file selected.</p>
                    </div>

                    <section class="partner-product-selector" data-product-selector>
                        <div class="partner-product-selector-head">
                            <div>
                                <span>Approved SKUs</span>
                                <strong>Select products</strong>
                            </div>
                            <label class="partner-sku-search">
                                <span>Search</span>
                                <input type="search" placeholder="SKU, product, flavor, tag" data-sku-search>
                            </label>
                        </div>
                        <div class="partner-filter-block">
                            <span>Product</span>
                            <div class="partner-filter-pills" data-product-filter>
                                <button type="button" class="is-active" data-product-value="">All</button>
                            </div>
                        </div>
                        <div class="partner-filter-block">
                            <span>Flavor</span>
                            <div class="partner-filter-pills" data-flavor-filter>
                                <button type="button" class="is-active" data-flavor-value="">All</button>
                            </div>
                        </div>
                        <div class="partner-sku-list" data-sku-list>
                            <p class="admin-empty">Approved SKUs will load after your session is ready.</p>
                        </div>
                    </section>
                </div>

                <aside class="partner-label-preview">
                    <span>Order preview</span>
                    <div class="partner-preview-stack" data-order-preview></div>
                    <p class="admin-form-error" data-modal-order-error hidden></p>
                    <button type="submit" class="admin-primary-btn" data-submit-order disabled>Submit Order</button>
                </aside>
            </section>
        </form>
    </div>

    <div class="admin-modal-shell partner-settings-modal" data-favicon-modal hidden>
        <div class="admin-modal-backdrop" data-close-favicon-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="favicon-modal-title">
            <div class="admin-modal-head">
                <div>
                    <span class="admin-panel-kicker">Appearance</span>
                    <h3 id="favicon-modal-title">Browser favicon</h3>
                </div>
                <button type="button" class="admin-ghost-btn" data-close-favicon-modal>Close</button>
            </div>
            <p class="partner-settings-modal-copy">Upload one icon for light mode and another for dark mode. Empty slots use the Jenang Gemi icon.</p>
            <div class="partner-favicon-grid" data-favicon-settings>
                <?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $faviconTheme => $faviconLabel): ?>
                    <?php $favicon = $faviconSettings[$faviconTheme] ?? ['configured' => false, 'url' => '', 'name' => '']; ?>
                    <form class="partner-favicon-card" data-favicon-form data-favicon-theme="<?php echo $faviconTheme; ?>">
                        <div class="partner-favicon-preview <?php echo !empty($favicon['configured']) ? 'is-configured' : ''; ?>" data-favicon-preview>
                            <img <?php if (!empty($favicon['configured'])): ?>src="<?php echo htmlspecialchars((string) ($favicon['url'] ?? ''), ENT_QUOTES); ?>"<?php endif; ?> alt="" <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>>
                            <span data-favicon-empty <?php echo !empty($favicon['configured']) ? 'hidden' : ''; ?>>Empty</span>
                        </div>
                        <div class="partner-favicon-card-copy">
                            <strong><?php echo $faviconLabel; ?></strong>
                            <span data-favicon-name><?php echo htmlspecialchars((string) ($favicon['name'] ?? '') ?: 'No custom favicon', ENT_QUOTES); ?></span>
                        </div>
                        <input type="file" name="favicon" accept=".png,.ico,image/png,image/x-icon" data-favicon-input hidden>
                        <div class="partner-favicon-actions">
                            <button type="button" class="admin-ghost-btn" data-choose-favicon><?php echo !empty($favicon['configured']) ? 'Replace' : 'Upload'; ?></button>
                            <button type="button" class="admin-danger-btn" data-remove-favicon <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>>Remove</button>
                        </div>
                        <p class="admin-form-error" data-favicon-error hidden></p>
                    </form>
                <?php endforeach; ?>
            </div>
            <small class="partner-favicon-help">PNG or ICO, maximum 1 MB. PNG files must be square, from 16×16 to 1024×1024.</small>
        </div>
    </div>

    <div class="admin-modal-shell partner-settings-modal" data-platform-settings-modal hidden>
        <div class="admin-modal-backdrop" data-close-platform-settings-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="platform-settings-modal-title">
            <div class="admin-modal-head">
                <div>
                    <span class="admin-panel-kicker">Order workflow</span>
                    <h3 id="platform-settings-modal-title">Platform options</h3>
                </div>
                <button type="button" class="admin-ghost-btn" data-close-platform-settings-modal>Close</button>
            </div>
            <p class="partner-settings-modal-copy">Add reseller profiles for order entry and reporting. Built-in marketplaces are always available.</p>
            <form class="partner-platform-profile-form" data-platform-profile-form>
                <label>
                    <span>Reseller or platform name</span>
                    <input type="text" name="platform_name" maxlength="32" placeholder="e.g. Bandung Reseller" autocomplete="off" required>
                </label>
                <button type="submit" class="admin-primary-btn">Add platform</button>
            </form>
            <p class="admin-form-error" data-platform-profile-error hidden></p>
            <div class="partner-platform-profile-list" data-platform-profile-list>
                <p class="admin-empty">Loading platform options.</p>
            </div>
        </div>
    </div>

    <div class="admin-modal-shell partner-settings-modal partner-password-modal" data-password-modal hidden>
        <div class="admin-modal-backdrop" data-close-password-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
            <div class="admin-modal-head">
                <div>
                    <span class="admin-panel-kicker">Security</span>
                    <h3 id="password-modal-title">Change password</h3>
                </div>
                <button type="button" class="admin-ghost-btn" data-close-password-modal>Close</button>
            </div>
            <form class="admin-affiliate-editor" data-password-form>
                <p class="admin-table-note" data-password-reset-note hidden>Set a new password to finish unlocking this workspace.</p>
                <label class="admin-affiliate-field" data-current-password-field>
                    <span class="admin-control-label">Current password</span>
                    <input type="password" name="current_password" autocomplete="current-password" required data-current-password-input>
                </label>
                <label class="admin-affiliate-field">
                    <span class="admin-control-label">New password</span>
                    <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                </label>
                <label class="admin-affiliate-field">
                    <span class="admin-control-label">Confirm new password</span>
                    <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                </label>
                <p class="admin-form-error" data-password-error hidden></p>
                <div class="admin-modal-actions">
                    <button type="button" class="admin-ghost-btn" data-close-password-modal>Cancel</button>
                    <button type="submit" class="admin-primary-btn">Save Password</button>
                </div>
            </form>
        </div>
    </div>

    <script type="module" src="/dashboard.js?v=<?php echo urlencode($dashboardJsVersion ?: '1'); ?>"></script>
</body>
</html>
