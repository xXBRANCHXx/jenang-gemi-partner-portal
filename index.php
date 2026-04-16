<?php
declare(strict_types=1);

require __DIR__ . '/partner-auth.php';

$hasError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = (string) ($_POST['partner_code'] ?? '');
    $submittedName = (string) ($_POST['partner_name'] ?? '');
    if (jg_partner_attempt_login($submittedCode, $submittedName)) {
        header('Location: ./dashboard/');
        exit;
    }
    $hasError = true;
}

if (jg_partner_is_authenticated()) {
    header('Location: ./dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(__DIR__ . '/admin.css');
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
    <link rel="stylesheet" href="./admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-login">
    <main class="admin-login-shell">
        <section class="admin-login-card">
            <div class="admin-login-brand">
                <span class="admin-chip">Partner Portal Access</span>
                <h1>Jenang Gemi Partner Portal</h1>
                <p>Sign in with your partner code and registered partner name to access your dashboard on `partner.jenanggemi.com`.</p>
            </div>
            <form method="post" class="admin-login-form" autocomplete="off">
                <label for="partner_code">Partner Code</label>
                <input id="partner_code" name="partner_code" type="text" placeholder="e.g. partner-001-demo-partner" required autofocus>
                <label for="partner_name">Partner Name</label>
                <input id="partner_name" name="partner_name" type="text" placeholder="Enter the partner name from your profile" required>
                <?php if ($hasError): ?>
                    <p class="admin-login-error">Partner code or partner name is invalid.</p>
                <?php endif; ?>
                <button type="submit" class="admin-primary-btn">Access Dashboard</button>
            </form>
        </section>
    </main>
</body>
</html>
