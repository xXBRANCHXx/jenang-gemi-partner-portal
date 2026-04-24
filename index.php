<?php
declare(strict_types=1);

require __DIR__ . '/partner-auth.php';

$requestPath = trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', '/');
$knownStaticPrefixes = ['dashboard', 'logout', 'api'];
$requestedPartner = null;

if ($requestPath !== '' && !in_array(explode('/', $requestPath)[0], $knownStaticPrefixes, true)) {
    $requestedPartner = jg_partner_source_find_by_slug($requestPath);
    if ($requestedPartner === null) {
        http_response_code(404);
    }
}

$hasError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = (string) ($_POST['partner_code'] ?? '');
    if (jg_partner_attempt_login($submittedCode)) {
        header('Location: /dashboard/');
        exit;
    }
    $hasError = true;
}

if (jg_partner_is_authenticated()) {
    header('Location: /dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(__DIR__ . '/admin.css');
$portalTitle = $requestedPartner ? ((string) ($requestedPartner['name'] ?? 'Partner Portal')) : 'Jenang Gemi Partner Portal';
$portalChip = $requestedPartner ? 'Partner Login' : 'Partner Portal Access';
$portalCopy = $requestedPartner
    ? 'Enter the partner code for this workspace to access the dashboard.'
    : 'Enter your partner code to access your dashboard on `partner.jenanggemi.com`.';
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
                <input id="partner_code" name="partner_code" type="text" placeholder="Enter your partner code" autocomplete="one-time-code" required autofocus>
                <?php if ($hasError): ?>
                    <p class="admin-login-error">Partner code is invalid.</p>
                <?php endif; ?>
                <?php if ($requestPath !== '' && $requestedPartner === null): ?>
                    <p class="admin-login-error">That partner page was not found.</p>
                <?php endif; ?>
                <button type="submit" class="admin-primary-btn">Access Dashboard</button>
            </form>
        </section>
    </main>
</body>
</html>
