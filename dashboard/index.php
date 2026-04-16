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
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.00.01</div>
    <div class="admin-app" data-partner-dashboard data-session-endpoint="../api/session/" data-orders-endpoint="../api/orders/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip" data-partner-code><?php echo htmlspecialchars((string) ($partner['code'] ?? 'PARTNER'), ENT_QUOTES); ?></span>
                <h1 data-partner-name><?php echo htmlspecialchars((string) ($partner['name'] ?? 'Partner Dashboard'), ENT_QUOTES); ?></h1>
                <p>Create, edit, and delete your draft orders here. The catalog shown is restricted by your admin profile.</p>
            </div>
            <div class="admin-topbar-actions">
                <button type="button" class="admin-ghost-btn" data-partner-logout>Logout</button>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Partner Dashboard</span>
                    <h2>Simple ordering surface for partners and dropshippers.</h2>
                    <p>Your available brands and products are controlled by the admin profile created in the executive dashboard.</p>
                </div>
                <div class="admin-hero-actions">
                    <div class="admin-status-pill">
                        <span class="admin-status-dot"></span>
                        <span>Session Active</span>
                    </div>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Allowed Brands</span><strong>Live</strong><small data-allowed-brands></small></article>
                <article class="admin-metric-card"><span>Allowed Products</span><strong>Live</strong><small data-allowed-products></small></article>
                <article class="admin-metric-card"><span>Orders</span><strong>Draft</strong><small>Create, edit, or delete draft orders</small></article>
                <article class="admin-metric-card"><span>Pricing</span><strong>From Admin</strong><small>Agreement values are managed in the executive dashboard</small></article>
            </section>

            <section class="admin-panel admin-panel-affiliates">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">New Order</span>
                        <h3>Create or update a draft order</h3>
                    </div>
                </div>
                <form class="admin-affiliate-editor" data-order-form>
                    <input type="hidden" name="order_id">
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Customer name</span>
                        <input type="text" name="customer_name" maxlength="160" required>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Brand</span>
                        <select class="admin-select" name="brand" required></select>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Product</span>
                        <select class="admin-select" name="product" required></select>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Flavor</span>
                        <select class="admin-select" name="flavor" required></select>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Size</span>
                        <select class="admin-select" name="size" required></select>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Quantity</span>
                        <input type="number" name="quantity" min="1" step="1" value="1" required>
                    </label>
                    <label class="admin-affiliate-field">
                        <span class="admin-control-label">Notes</span>
                        <input type="text" name="notes" maxlength="300">
                    </label>
                    <p class="admin-form-error" data-order-error hidden></p>
                    <div class="admin-affiliate-actions">
                        <button type="submit" class="admin-primary-btn">Create Order</button>
                    </div>
                </form>
            </section>

            <section class="admin-panel admin-panel-affiliates">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Draft Orders</span>
                        <h3>Your order list</h3>
                    </div>
                </div>
                <div class="admin-affiliate-list" data-order-list>
                    <p class="admin-empty">No draft orders yet.</p>
                </div>
            </section>
        </main>
    </div>

    <script type="module" src="../dashboard.js?v=<?php echo urlencode($dashboardJsVersion ?: '1'); ?>"></script>
</body>
</html>
