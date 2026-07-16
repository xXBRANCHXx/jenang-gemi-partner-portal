<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-auth.php';

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
$logoutUrl = $workspaceBase . '/logout/';
$partnerName = (string) ($partner['name'] ?? 'Partner Dashboard');
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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Dashboard | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
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
                            <button type="button" class="admin-ghost-btn" data-refresh-orders>Refresh</button>
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
                <section class="partner-settings-grid">
                    <article class="partner-panel">
                        <div class="partner-panel-head">
                            <div>
                                <span>Appearance</span>
                                <h3>Theme</h3>
                            </div>
                        </div>
                        <div class="partner-settings-row">
                            <span>Default follows your device. Choose Light or Dark to override it on this browser.</span>
                            <div class="partner-theme-switch is-inline" data-theme-switch aria-label="Theme preference">
                                <button type="button" data-theme-option="system">System</button>
                                <button type="button" data-theme-option="light">Light</button>
                                <button type="button" data-theme-option="dark">Dark</button>
                            </div>
                        </div>
                    </article>

                    <article class="partner-panel">
                        <div class="partner-panel-head">
                            <div>
                                <span>Security</span>
                                <h3>Password</h3>
                            </div>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-open-password-modal>Change Password</button>
                    </article>

                    <article class="partner-panel partner-platform-settings-panel">
                        <div class="partner-panel-head">
                            <div>
                                <span>Order routing</span>
                                <h3>Platform options</h3>
                            </div>
                        </div>
                        <p class="partner-settings-copy">Add reseller profiles for order entry and platform-level metrics. Built-in marketplaces stay available automatically.</p>
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
                    </article>
                </section>
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

    <div class="admin-modal-shell" data-password-modal hidden>
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
