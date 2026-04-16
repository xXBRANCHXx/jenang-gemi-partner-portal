<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Portal | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Partner portal build version">Build 1.00.00</div>
    <div class="admin-app">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Partner Portal</span>
                <h1>Jenang Gemi Partner Portal</h1>
                <p>Manage partner profiles, company assignment, allowed brands, product access, and pricing agreements for `partner.jenanggemi.com`.</p>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-primary-btn admin-link-btn" href="../profiles/">Partner Profiles</a>
                <a class="admin-ghost-btn admin-link-btn" href="../logout/">Lock</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Partner Management Layer</span>
                    <h2>Keep partner permissions and pricing here while SKU, inventory, and orders live in Store Ops.</h2>
                    <p>This repo is the boundary between executive administration and store operations. It should decide which companies, brands, products, and pricing agreements apply to each partner.</p>
                </div>
                <div class="admin-hero-actions">
                    <a class="admin-primary-btn admin-link-btn" href="../profiles/">Open Partner Profiles</a>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Profiles</span><strong>Live</strong><small>Create and edit partner records</small></article>
                <article class="admin-metric-card"><span>Companies</span><strong>Live</strong><small>Jenang Gemi, ZERO, ZFIT assignment</small></article>
                <article class="admin-metric-card"><span>Pricing</span><strong>Live</strong><small>Per-partner agreement fields</small></article>
                <article class="admin-metric-card"><span>Store Sync</span><strong>Planned</strong><small>Future API bridge to Store Ops</small></article>
            </section>
        </main>
    </div>
</body>
</html>
