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
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.01.00</div>
    <div
        class="partner-dashboard-app partner-workspace"
        data-partner-dashboard
        data-session-endpoint="<?php echo htmlspecialchars($sessionEndpoint, ENT_QUOTES); ?>"
        data-orders-endpoint="<?php echo htmlspecialchars($ordersEndpoint, ENT_QUOTES); ?>"
        data-labels-endpoint="<?php echo htmlspecialchars($labelsEndpoint, ENT_QUOTES); ?>"
        data-logout-url="<?php echo htmlspecialchars($logoutUrl, ENT_QUOTES); ?>"
    >
        <aside class="partner-sidebar">
            <div class="partner-sidebar-brand">
                <span class="partner-sidebar-kicker">Partner Workspace</span>
                <h1 data-partner-name><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></h1>
                <p data-partner-code>Direct ordering portal</p>
            </div>

            <nav class="partner-sidebar-nav" aria-label="Partner navigation">
                <button type="button" class="is-active" data-partner-nav="overview">Overview</button>
                <button type="button" data-partner-nav="orders">Orders</button>
                <button type="button" data-partner-nav="history">History</button>
                <button type="button" data-partner-nav="labels">Labels</button>
                <button type="button" data-partner-nav="analytics">Analytics</button>
            </nav>

            <button type="button" class="partner-sidebar-primary" data-open-order-modal>New Label Order</button>

            <div class="partner-sidebar-profile">
                <strong><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></strong>
                <span>Active partner</span>
            </div>

            <div class="partner-sidebar-actions">
                <button type="button" data-open-password-modal>Change Password</button>
                <button type="button" data-partner-logout>Logout</button>
            </div>
        </aside>

        <main class="partner-main">
            <header class="partner-page-head">
                <div>
                    <span>Partner portal</span>
                    <h2>Overview</h2>
                </div>
                <button type="button" class="admin-primary-btn" data-open-order-modal>Upload Label</button>
            </header>

            <section class="partner-metric-grid">
                <article><span>30D units</span><strong data-metric-units>0</strong><small>Recent sell-through</small></article>
                <article><span>Orders reconstructed</span><strong data-metric-orders>0</strong><small>This window</small></article>
                <article><span>Avg. units/order</span><strong data-metric-average>0.0</strong><small>Last 30 days</small></article>
                <article><span>Revenue</span><strong data-metric-revenue>Rp0</strong><small>Partner pricing</small></article>
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
                    <div class="partner-timeframe-toggle" data-timeframe-toggle>
                        <button type="button" data-timeframe="24h">24H</button>
                        <button type="button" data-timeframe="7d">7D</button>
                        <button type="button" data-timeframe="30d">30D</button>
                        <button type="button" data-timeframe="90d">90D</button>
                        <button type="button" data-timeframe="year">Year</button>
                        <button type="button" data-timeframe="all">All</button>
                    </div>
                    <div class="partner-bars" data-sales-chart></div>
                </article>

                <article class="partner-panel">
                    <div class="partner-panel-head">
                        <div>
                            <span>Recent orders</span>
                            <h3>Reconstructed history</h3>
                        </div>
                    </div>
                    <div class="partner-recent-list" data-recent-orders></div>
                </article>
            </section>

            <section class="partner-panel partner-orders-panel">
                <div class="partner-panel-head">
                    <div>
                        <span>Orders</span>
                        <h3>Order history</h3>
                    </div>
                    <button type="button" class="admin-primary-btn" data-open-order-modal>New Label Order</button>
                </div>
                <p class="admin-form-error" data-order-error hidden></p>
                <div class="partner-order-card-list" data-order-list>
                    <p class="admin-empty">No orders yet.</p>
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
                            <span class="admin-panel-kicker">New label order</span>
                            <h3 id="order-modal-title">Reconstruct order from shipping label</h3>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-close-order-modal>Close</button>
                    </div>

                    <div class="partner-label-controls">
                        <label class="partner-label-field">
                            <span>Creation time</span>
                            <input type="datetime-local" name="order_timestamp" data-order-timestamp required>
                        </label>
                        <label class="partner-label-field">
                            <span>Deadline</span>
                            <strong data-deadline-value>24h</strong>
                            <input type="range" name="deadline_hours" min="1" max="48" value="24" data-deadline-range>
                        </label>
                    </div>

                    <button type="button" class="partner-upload-dropzone partner-label-dropzone" data-label-dropzone>
                        <span class="partner-upload-plus" aria-hidden="true">+</span>
                        <strong data-label-dropzone-copy>Upload shipping label</strong>
                        <span>Shopee, TikTok Shop, PDF, image, ZPL, TXT, or PRN</span>
                    </button>
                    <input type="file" name="labels" data-label-input hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.svg,.zpl,.txt,.prn">

                    <div class="partner-upload-queue" data-label-queue>
                        <p class="admin-empty">No label file selected.</p>
                    </div>

                    <div class="partner-analysis-card" data-analysis-card>
                        <div class="partner-analysis-head">
                            <div>
                                <span>Source detection</span>
                                <strong data-analysis-platform>Waiting for label</strong>
                            </div>
                            <b data-analysis-confidence>0%</b>
                        </div>
                        <p data-analysis-reasons>No label analyzed yet.</p>
                    </div>

                    <div class="partner-analysis-card">
                        <div class="partner-analysis-head">
                            <div>
                                <span>Matched item tags</span>
                                <strong data-analysis-item-count>0 SKUs</strong>
                            </div>
                        </div>
                        <div class="partner-match-list" data-analysis-items>
                            <p class="admin-empty">Upload a label to detect SKUs.</p>
                        </div>
                    </div>
                </div>

                <aside class="partner-label-preview">
                    <span>Order preview</span>
                    <div class="partner-preview-stack" data-order-preview></div>
                    <p class="admin-form-error" data-modal-order-error hidden></p>
                    <button type="submit" class="admin-primary-btn" data-submit-order disabled>Submit Reconstructed Order</button>
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
                <label class="admin-affiliate-field">
                    <span class="admin-control-label">Current password</span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
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
