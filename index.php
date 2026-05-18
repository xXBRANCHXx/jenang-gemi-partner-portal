<?php
declare(strict_types=1);

require __DIR__ . '/partner-auth.php';

$requestPath = trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');
$knownStaticPrefixes = ['dashboard', 'logout', 'api'];
$pathParts = $requestPath === '' ? [] : explode('/', $requestPath);
$firstSegment = (string) ($pathParts[0] ?? '');
$routeParts = array_slice($pathParts, 1);
$route = implode('/', $routeParts);
$requestedPartner = null;

if ($firstSegment !== '' && !in_array($firstSegment, $knownStaticPrefixes, true)) {
    $requestedPartner = jg_partner_source_find_by_slug($firstSegment);
    if ($requestedPartner === null) {
        http_response_code(404);
    }
}

if ($requestedPartner !== null && $route !== '') {
    if ($route === 'dashboard' || str_starts_with($route, 'dashboard/')) {
        if (!jg_partner_is_authenticated_for($requestedPartner)) {
            header('Location: ' . jg_partner_login_path($requestedPartner));
            exit;
        }
        require __DIR__ . '/dashboard/index.php';
        exit;
    }

    if ($route === 'logout' || str_starts_with($route, 'logout/')) {
        jg_partner_logout();
        header('Location: ' . jg_partner_login_path($requestedPartner));
        exit;
    }

    if ($route === 'api/session' || str_starts_with($route, 'api/session/')) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'DELETE') {
            jg_partner_require_auth_for_json($requestedPartner);
        }
        require __DIR__ . '/api/session/index.php';
        exit;
    }

    if ($route === 'api/orders' || str_starts_with($route, 'api/orders/')) {
        jg_partner_require_auth_for_json($requestedPartner);
        require __DIR__ . '/api/orders/index.php';
        exit;
    }

    if ($route === 'api/order-labels' || str_starts_with($route, 'api/order-labels/')) {
        jg_partner_require_auth_for_json($requestedPartner);
        require __DIR__ . '/api/order-labels/index.php';
        exit;
    }

    http_response_code(404);
}

$hasError = false;
$requiresPartnerUrl = $requestedPartner === null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = (string) ($_POST['partner_code'] ?? '');
    if ($requestedPartner !== null && jg_partner_attempt_login($submittedCode, $requestedPartner)) {
        header('Location: ' . jg_partner_dashboard_path($requestedPartner));
        exit;
    }
    $hasError = true;
}

if ($requestedPartner !== null && jg_partner_is_authenticated_for($requestedPartner)) {
    header('Location: ' . jg_partner_dashboard_path($requestedPartner));
    exit;
}

if ($requestedPartner === null && $requestPath === '' && jg_partner_is_authenticated()) {
    header('Location: ' . jg_partner_dashboard_path());
    exit;
}

$adminCssVersion = (string) @filemtime(__DIR__ . '/admin.css');
$portalTitle = $requestedPartner ? ((string) ($requestedPartner['name'] ?? 'Partner Portal')) : 'Jenang Gemi Partner Portal';
$portalChip = $requestedPartner ? 'Partner Login' : 'Partner Portal Access';
$portalCopy = $requestedPartner
    ? 'Enter the partner code assigned to this workspace to access the dashboard.'
    : 'Use your assigned partner URL to access your dashboard.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Partner Login | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="/admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-login">
    <main class="admin-login-shell">
        <section class="admin-login-card">
            <div class="admin-login-brand">
                <span class="admin-chip"><?php echo htmlspecialchars($portalChip, ENT_QUOTES); ?></span>
                <h1><?php echo htmlspecialchars($portalTitle, ENT_QUOTES); ?></h1>
                <p><?php echo htmlspecialchars($portalCopy, ENT_QUOTES); ?></p>
            </div>
            <form method="post" class="admin-login-form" autocomplete="off">
                <label for="partner_code">Partner Code</label>
                <input id="partner_code" name="partner_code" type="text" placeholder="Enter your partner code" autocomplete="one-time-code" required autofocus <?php echo $requiresPartnerUrl ? 'disabled' : ''; ?>>
                <?php if ($hasError): ?>
                    <p class="admin-login-error">Partner code is invalid for this workspace.</p>
                <?php endif; ?>
                <?php if ($requestPath !== '' && $requestedPartner === null): ?>
                    <p class="admin-login-error">That partner page was not found.</p>
                <?php elseif ($requiresPartnerUrl): ?>
                    <p class="admin-login-error">Open the unique URL assigned to your partner workspace.</p>
                <?php endif; ?>
                <button type="submit" class="admin-primary-btn" <?php echo $requiresPartnerUrl ? 'disabled' : ''; ?>>Access Dashboard</button>
            </form>
        </section>
    </main>
</body>
</html>
