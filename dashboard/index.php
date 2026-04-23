<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-auth.php';

if (!jg_partner_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$dashboardJsVersion = (string) @filemtime(dirname(__DIR__) . '/dashboard.js');
$partner = jg_partner_current_profile();
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
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.02.00</div>
    <div class="admin-app partner-dashboard-app" data-partner-dashboard data-session-endpoint="../api/session/" data-orders-endpoint="../api/orders/" data-labels-endpoint="../api/order-labels/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip" data-partner-code><?php echo htmlspecialchars((string) ($partner['code'] ?? 'PARTNER'), ENT_QUOTES); ?></span>
                <h1 data-partner-name><?php echo htmlspecialchars((string) ($partner['name'] ?? 'Partner Dashboard'), ENT_QUOTES); ?></h1>
            </div>
            <div class="admin-topbar-actions">
                <button type="button" class="admin-primary-btn" data-open-order-modal>New Order</button>
                <button type="button" class="admin-ghost-btn" data-partner-logout>Logout</button>
            </div>
        </header>

        <main class="admin-layout">
            <section class="partner-analytics-toolbar admin-panel admin-panel-affiliates">
                <div>
                    <span class="admin-panel-kicker">Sales Window</span>
                    <h3>Units sold by timeframe</h3>
                </div>
                <div class="partner-timeframe-toggle" data-timeframe-toggle>
                    <button type="button" data-timeframe="24h">24H</button>
                    <button type="button" data-timeframe="7d">7D</button>
                    <button type="button" data-timeframe="30d">30D</button>
                    <button type="button" data-timeframe="90d">90D</button>
                    <button type="button" data-timeframe="year">Year</button>
                    <button type="button" data-timeframe="all">All</button>
                </div>
            </section>

            <section class="partner-analytics-dashboard">
                <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Sales Analytics</span>
                            <h3 data-sales-chart-title>Sales by timeframe</h3>
                        </div>
                        <span class="partner-hero-chip" data-sales-summary>0 units</span>
                    </div>
                    <div class="partner-chart" data-sales-chart></div>
                </section>

                <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Time Activity</span>
                            <h3>Most busy hours</h3>
                        </div>
                        <span class="partner-hero-chip" data-busiest-hour>00:00</span>
                    </div>
                    <div class="partner-chart partner-hour-chart" data-hourly-chart></div>
                </section>
            </section>

            <section class="partner-analytics-dashboard">
                <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Product Insights</span>
                            <h3>Share of selected sales</h3>
                        </div>
                    </div>
                    <div class="partner-insight-list" data-product-insights></div>
                </section>

                <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Flavor Insights</span>
                            <h3>Flavor mix</h3>
                        </div>
                    </div>
                    <div class="partner-insight-list" data-flavor-insights></div>
                </section>
            </section>

            <section class="admin-panel admin-panel-affiliates">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Orders</span>
                        <h3>Order history</h3>
                    </div>
                    <button type="button" class="admin-primary-btn" data-open-order-modal>New Order</button>
                </div>
                <div class="partner-order-table-wrap">
                    <table class="partner-order-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Invoice</th>
                                <th>Qty</th>
                                <th>Label</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody data-order-list>
                            <tr><td colspan="7" class="partner-order-empty">No orders yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="admin-modal-shell partner-order-modal-shell" data-order-modal hidden>
        <div class="admin-modal-backdrop" data-close-order-modal></div>
        <div class="admin-modal-card partner-order-modal" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
            <div class="admin-modal-head">
                <div>
                    <span class="admin-panel-kicker">Order</span>
                    <h3 id="order-modal-title">Create order</h3>
                </div>
                <button type="button" class="admin-ghost-btn" data-close-order-modal>Close</button>
            </div>
            <form class="admin-affiliate-editor partner-order-form" data-order-form>
                <input type="hidden" name="order_id">
                <section class="partner-order-modal-step">
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Customer name</span>
                        <input type="text" name="customer_name" maxlength="160" placeholder="Customer full name" required>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Timestamp</span>
                        <input type="datetime-local" name="order_timestamp" required>
                    </label>
                </section>

                <section class="partner-invoice-builder">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Invoice</span>
                            <h3>Add products</h3>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-add-invoice-item>Add Product</button>
                    </div>
                    <div class="partner-invoice-items" data-invoice-items></div>
                </section>

                <section class="partner-upload-card">
                    <div class="partner-upload-head">
                        <div>
                            <span class="admin-panel-kicker">Shipping Label</span>
                            <h4>One label per order</h4>
                        </div>
                        <p>Upload one PDF, image, ZPL, TXT, or PRN file. Delete the current label before replacing it.</p>
                    </div>
                    <button type="button" class="partner-upload-dropzone" data-label-dropzone>
                        <span class="partner-upload-plus" aria-hidden="true">+</span>
                        <strong>Upload shipping label</strong>
                        <span data-label-dropzone-copy>Add one label after the order is saved.</span>
                    </button>
                    <input type="file" name="labels" data-label-input hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.svg,.zpl,.txt,.prn">
                    <div class="partner-upload-queue" data-label-queue>
                        <p class="admin-empty">No label file queued.</p>
                    </div>
                </section>

                <label class="admin-affiliate-field">
                    <span class="admin-control-label">Notes</span>
                    <input type="text" name="notes" maxlength="300" placeholder="Optional internal note">
                </label>
                <p class="admin-form-error" data-order-error hidden></p>
                <div class="admin-modal-actions">
                    <button type="button" class="admin-ghost-btn" data-close-order-modal>Cancel</button>
                    <button type="submit" class="admin-primary-btn">Create Order</button>
                </div>
            </form>
        </div>
    </div>

    <script type="module" src="../dashboard.js?v=<?php echo urlencode($dashboardJsVersion ?: '1'); ?>"></script>
</body>
</html>
