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
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.01.00</div>
    <div class="admin-app partner-dashboard-app" data-partner-dashboard data-session-endpoint="../api/session/" data-orders-endpoint="../api/orders/" data-labels-endpoint="../api/order-labels/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip" data-partner-code><?php echo htmlspecialchars((string) ($partner['code'] ?? 'PARTNER'), ENT_QUOTES); ?></span>
                <h1 data-partner-name><?php echo htmlspecialchars((string) ($partner['name'] ?? 'Partner Dashboard'), ENT_QUOTES); ?></h1>
                <p>Manage orders, upload shipping labels, and watch partner activity from a SKU-locked workspace that mirrors your approved catalog.</p>
            </div>
            <div class="admin-topbar-actions">
                <button type="button" class="admin-ghost-btn" data-partner-logout>Logout</button>
            </div>
        </header>

        <main class="admin-layout">
            <section class="partner-hero">
                <div class="partner-hero-copy">
                    <span class="admin-chip admin-chip-accent">Partner Workspace</span>
                    <h2>Shopee-style flow, tuned for Jenang Gemi and filtered to the SKUs your admin enabled.</h2>
                    <p>Choose a brand, then product, then exact SKU. Create orders, attach shipping labels, and review trends by month and by busiest hour.</p>
                </div>
                <div class="partner-hero-status">
                    <div class="admin-status-pill">
                        <span class="admin-status-dot"></span>
                        <span>Session Active</span>
                    </div>
                    <div class="partner-hero-chip" data-storage-mode>Storage Loading</div>
                </div>
            </section>

            <section class="partner-summary-grid">
                <article class="partner-summary-card">
                    <span>Allowed Brands</span>
                    <strong data-brand-count>0</strong>
                    <small data-allowed-brands></small>
                </article>
                <article class="partner-summary-card">
                    <span>Allowed Products</span>
                    <strong data-product-count>0</strong>
                    <small data-allowed-products></small>
                </article>
                <article class="partner-summary-card">
                    <span>Orders</span>
                    <strong data-order-count>0</strong>
                    <small>Total recorded in your workspace</small>
                </article>
                <article class="partner-summary-card">
                    <span>Busiest Time</span>
                    <strong data-busiest-hour>00:00</strong>
                    <small>Based on your saved orders</small>
                </article>
            </section>

            <section class="partner-content-grid">
                <section class="admin-panel admin-panel-affiliates partner-order-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Order Entry</span>
                            <h3>Create or update an order</h3>
                        </div>
                    </div>
                    <form class="admin-affiliate-editor partner-order-form" data-order-form>
                        <input type="hidden" name="order_id">
                        <label class="admin-affiliate-field">
                            <span class="admin-control-label">Customer name</span>
                            <input type="text" name="customer_name" maxlength="160" placeholder="Customer full name" required>
                        </label>
                        <div class="partner-order-step-grid">
                            <label class="admin-affiliate-field">
                                <span class="admin-control-label">Brand</span>
                                <select class="admin-select" name="brand" required></select>
                            </label>
                            <label class="admin-affiliate-field">
                                <span class="admin-control-label">Product</span>
                                <select class="admin-select" name="product" required></select>
                            </label>
                        </div>
                        <label class="admin-affiliate-field">
                            <span class="admin-control-label">SKU</span>
                            <select class="admin-select" name="sku_code" required></select>
                        </label>
                        <div class="partner-selected-sku" data-selected-sku-card>
                            <strong data-selected-sku-name>Select a SKU</strong>
                            <span data-selected-sku-meta>The selected SKU details will show here.</span>
                        </div>
                        <div class="partner-order-step-grid">
                            <label class="admin-affiliate-field">
                                <span class="admin-control-label">Quantity</span>
                                <input type="number" name="quantity" min="1" step="1" value="1" required>
                            </label>
                            <label class="admin-affiliate-field">
                                <span class="admin-control-label">Notes</span>
                                <input type="text" name="notes" maxlength="300" placeholder="Optional internal note">
                            </label>
                        </div>
                        <section class="partner-upload-card">
                            <div class="partner-upload-head">
                                <div>
                                    <span class="admin-panel-kicker">Shipping Label</span>
                                    <h4>Drag and drop, click to upload, or tap the plus button</h4>
                                </div>
                                <p>Accepted: PDF, PNG, JPG, JPEG, WEBP, GIF, SVG, ZPL, TXT, PRN.</p>
                            </div>
                            <button type="button" class="partner-upload-dropzone" data-label-dropzone>
                                <span class="partner-upload-plus" aria-hidden="true">+</span>
                                <strong>Upload shipping label</strong>
                                <span>Add files now. They will be attached after the order is saved.</span>
                            </button>
                            <input type="file" name="labels" data-label-input hidden multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.svg,.zpl,.txt,.prn">
                            <div class="partner-upload-queue" data-label-queue>
                                <p class="admin-empty">No label files queued.</p>
                            </div>
                        </section>
                        <p class="admin-form-error" data-order-error hidden></p>
                        <div class="admin-affiliate-actions">
                            <button type="submit" class="admin-primary-btn">Create Order</button>
                            <button type="button" class="admin-ghost-btn" data-reset-order-form>Reset</button>
                        </div>
                    </form>
                </section>

                <section class="partner-analytics-stack">
                    <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Monthly Analytics</span>
                                <h3>Orders per month</h3>
                            </div>
                            <div class="partner-year-toggle" data-year-toggle></div>
                        </div>
                        <div class="partner-chart" data-monthly-chart></div>
                    </section>

                    <section class="admin-panel admin-panel-affiliates partner-chart-panel">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Time Activity</span>
                                <h3>Most busy hours</h3>
                            </div>
                        </div>
                        <div class="partner-chart partner-hour-chart" data-hourly-chart></div>
                    </section>
                </section>
            </section>

            <section class="admin-panel admin-panel-affiliates">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Orders</span>
                        <h3>Your order table</h3>
                    </div>
                </div>
                <div class="partner-order-table-wrap">
                    <table class="partner-order-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>SKU</th>
                                <th>Qty</th>
                                <th>Labels</th>
                                <th>Updated</th>
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

    <script type="module" src="../dashboard.js?v=<?php echo urlencode($dashboardJsVersion ?: '1'); ?>"></script>
</body>
</html>
