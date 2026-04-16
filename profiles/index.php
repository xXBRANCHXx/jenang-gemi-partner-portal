<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$profilesJsVersion = (string) @filemtime(dirname(__DIR__) . '/profiles.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Profiles | Jenang Gemi Partner Portal</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-app" data-partner-profiles data-partners-endpoint="../api/partners/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Partner Profiles</span>
                <h1>Partner Profiles</h1>
                <p>Create partner records here and control which companies, brands, and products they can access.</p>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../dashboard/">Dashboard</a>
                <a class="admin-ghost-btn admin-link-btn" href="../logout/">Lock</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-panel admin-panel-wide">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">New Partner</span>
                        <h3>Create partner profile</h3>
                    </div>
                </div>
                <form class="admin-sku-builder" data-create-partner-form>
                    <label>
                        <span>Name</span>
                        <input type="text" name="name" maxlength="160" placeholder="e.g. Rina Sulistyo" required>
                    </label>
                    <label>
                        <span>Companies</span>
                        <select class="admin-select" name="companies" multiple size="3">
                            <option value="Jenang Gemi">Jenang Gemi</option>
                            <option value="ZERO">ZERO</option>
                            <option value="ZFIT">ZFIT</option>
                        </select>
                    </label>
                    <label>
                        <span>Allowed brands</span>
                        <input type="text" name="allowed_brands" placeholder="Jenang Gemi,ZERO,ZFIT">
                    </label>
                    <label>
                        <span>Allowed products</span>
                        <input type="text" name="products" placeholder="Bubur,Jamu">
                    </label>
                    <label>
                        <span>Bubur pricing agreement</span>
                        <input type="number" name="jenang_gemi_bubur" min="0" step="0.01" placeholder="e.g. 18000">
                    </label>
                    <label>
                        <span>Jamu pricing agreement</span>
                        <input type="number" name="jenang_gemi_jamu" min="0" step="0.01" placeholder="e.g. 22000">
                    </label>
                    <label>
                        <span>Notes</span>
                        <input type="text" name="notes" maxlength="300" placeholder="Optional note">
                    </label>
                    <div class="admin-sku-actions">
                        <button type="submit" class="admin-primary-btn">Create Partner</button>
                    </div>
                </form>
                <p class="admin-form-error" data-create-error hidden></p>
            </section>

            <section class="admin-panel admin-panel-affiliates">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Directory</span>
                        <h3>Partner profile list</h3>
                    </div>
                </div>
                <div class="admin-affiliate-list" data-partner-list>
                    <p class="admin-empty">No partners yet.</p>
                </div>
            </section>
        </main>
    </div>

    <script type="module" src="../profiles.js?v=<?php echo urlencode($profilesJsVersion ?: '1'); ?>"></script>
</body>
</html>
