<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$profileJsVersion = (string) @filemtime(dirname(__DIR__) . '/profile.js');
$code = trim((string) ($_GET['code'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Profile | Jenang Gemi Partner Portal</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-app" data-partner-profile data-partners-endpoint="../api/partners/" data-partner-code="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Partner Profile</span>
                <h1>Edit Partner Profile</h1>
                <p>Update company assignment, allowed brands/products, pricing agreements, and partner notes here.</p>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../profiles/">Back To Profiles</a>
                <a class="admin-ghost-btn admin-link-btn" href="../logout/">Lock</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-panel admin-panel-wide">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Edit</span>
                        <h3 data-partner-title>Loading partner</h3>
                    </div>
                </div>
                <form class="admin-sku-builder" data-edit-partner-form hidden>
                    <input type="hidden" name="code">
                    <label>
                        <span>Name</span>
                        <input type="text" name="name" maxlength="160" required>
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
                        <input type="text" name="allowed_brands">
                    </label>
                    <label>
                        <span>Allowed products</span>
                        <input type="text" name="products">
                    </label>
                    <label>
                        <span>Bubur pricing agreement</span>
                        <input type="number" name="jenang_gemi_bubur" min="0" step="0.01">
                    </label>
                    <label>
                        <span>Jamu pricing agreement</span>
                        <input type="number" name="jenang_gemi_jamu" min="0" step="0.01">
                    </label>
                    <label>
                        <span>Notes</span>
                        <input type="text" name="notes" maxlength="300">
                    </label>
                    <div class="admin-sku-preview">
                        <span class="admin-control-label">Store Path</span>
                        <strong data-store-path>Pending</strong>
                        <small>This is the partner-specific path planned for `store.jenanggemi.com`.</small>
                    </div>
                    <div class="admin-sku-actions">
                        <button type="submit" class="admin-primary-btn">Save Profile</button>
                    </div>
                </form>
                <p class="admin-form-error" data-edit-error hidden></p>
            </section>
        </main>
    </div>

    <script type="module" src="../profile.js?v=<?php echo urlencode($profileJsVersion ?: '1'); ?>"></script>
</body>
</html>
