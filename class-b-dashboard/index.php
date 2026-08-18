<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-auth.php';
require_once dirname(__DIR__) . '/partner-favicon-storage.php';
require_once dirname(__DIR__) . '/partner-preference-storage.php';

if (!jg_partner_is_authenticated()) {
    header('Location: /');
    exit;
}

$partner = jg_partner_current_profile();
$partnerName = trim((string) ($partner['name'] ?? 'Partner')) ?: 'Partner';
$partnerSlug = jg_partner_profile_slug($partner);
$base = $partnerSlug !== '' ? '/' . rawurlencode($partnerSlug) : '';
$faviconEndpoint = $base . '/api/favicon/';
$defaultFaviconUrl = 'https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png';
try {
    $faviconSettings = jg_partner_favicon_public_settings(jg_partner_current_code(), $faviconEndpoint);
} catch (Throwable) {
    $faviconSettings = ['light' => ['configured' => false, 'url' => '', 'name' => ''], 'dark' => ['configured' => false, 'url' => '', 'name' => '']];
}
try {
    $partnerPreferences = jg_partner_preferences(jg_partner_current_code());
} catch (Throwable) {
    $partnerPreferences = jg_partner_preference_defaults();
}
$lightFaviconUrl = (string) ($faviconSettings['light']['url'] ?? '') ?: $defaultFaviconUrl;
$darkFaviconUrl = (string) ($faviconSettings['dark']['url'] ?? '') ?: $defaultFaviconUrl;
$configuredFaviconThemes = array_values(array_filter(['light', 'dark'], static fn (string $theme): bool => !empty($faviconSettings[$theme]['configured'])));
$faviconSummary = match ($configuredFaviconThemes) {
    [] => 'Using the default icon',
    ['light'] => 'Custom light icon',
    ['dark'] => 'Custom dark icon',
    default => 'Custom light and dark icons',
};
$cssVersion = (string) @filemtime(__DIR__ . '/class-b-dashboard.css');
$jsVersion = (string) @filemtime(__DIR__ . '/class-b-dashboard.js');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($partnerPreferences['language'], ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?> · Stock Partner</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" data-partner-favicon="light" media="(prefers-color-scheme: light)" href="<?php echo htmlspecialchars($lightFaviconUrl, ENT_QUOTES); ?>">
    <link rel="icon" data-partner-favicon="dark" media="(prefers-color-scheme: dark)" href="<?php echo htmlspecialchars($darkFaviconUrl, ENT_QUOTES); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/admin.css?v=<?php echo urlencode((string) @filemtime(dirname(__DIR__) . '/admin.css')); ?>">
    <link rel="stylesheet" href="/class-b-dashboard/class-b-dashboard.css?v=<?php echo urlencode($cssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard" data-partner-theme="system">
<div class="cb-app partner-workspace" data-class-b-dashboard
     data-api="<?php echo htmlspecialchars($base . '/api/class-b/', ENT_QUOTES); ?>"
     data-session-api="<?php echo htmlspecialchars($base . '/api/session/', ENT_QUOTES); ?>"
     data-favicon-api="<?php echo htmlspecialchars($faviconEndpoint, ENT_QUOTES); ?>"
     data-logout="<?php echo htmlspecialchars($base . '/logout/', ENT_QUOTES); ?>"
     data-dashboard-base="<?php echo htmlspecialchars($base . '/dashboard/', ENT_QUOTES); ?>"
     data-language="<?php echo htmlspecialchars($partnerPreferences['language'], ENT_QUOTES); ?>"
     data-timezone="<?php echo htmlspecialchars($partnerPreferences['timezone'], ENT_QUOTES); ?>"
     data-default-favicon="<?php echo htmlspecialchars($defaultFaviconUrl, ENT_QUOTES); ?>"
     data-favicon-light-url="<?php echo htmlspecialchars((string) ($faviconSettings['light']['url'] ?? ''), ENT_QUOTES); ?>"
     data-favicon-light-name="<?php echo htmlspecialchars((string) ($faviconSettings['light']['name'] ?? ''), ENT_QUOTES); ?>"
     data-favicon-dark-url="<?php echo htmlspecialchars((string) ($faviconSettings['dark']['url'] ?? ''), ENT_QUOTES); ?>"
     data-favicon-dark-name="<?php echo htmlspecialchars((string) ($faviconSettings['dark']['name'] ?? ''), ENT_QUOTES); ?>"
     data-csrf="<?php echo htmlspecialchars(jg_partner_csrf_token(), ENT_QUOTES); ?>">
    <aside class="cb-sidebar" data-sidebar>
        <a class="cb-brand" href="<?php echo htmlspecialchars($base . '/dashboard/', ENT_QUOTES); ?>" aria-label="Partner dashboard home">
            <span><strong>JENANG GEMI</strong><small>Stock partner</small></span>
        </a>

        <nav aria-label="Partner navigation">
            <button type="button" class="is-active" data-view="overview">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                <span>Overview</span>
            </button>
            <button type="button" data-view="orders">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                <span>Stock orders</span><i data-order-nav-count hidden>0</i>
            </button>
            <button type="button" data-view="balance">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M16 13h2"/></svg>
                <span>Balance</span><i data-deposit-nav-count hidden>0</i>
            </button>
            <button type="button" data-view="settings">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 0 1 4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 1 1 7.1 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1a2 2 0 0 1 0 4H21a1.7 1.7 0 0 0-1.6 1z"/></svg>
                <span>Settings</span>
            </button>
        </nav>

        <div class="cb-sidebar-profile">
            <span class="cb-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($partnerName, 0, 1)), ENT_QUOTES); ?></span>
            <span><strong><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></strong><small>Active partner</small></span>
            <button type="button" data-logout aria-label="Log out" title="Log out"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg></button>
        </div>
    </aside>

    <main class="cb-main">
        <header class="cb-topbar">
            <button type="button" class="cb-menu" data-menu aria-label="Open navigation"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            <div><small data-page-kicker>Partner workspace</small><h1 data-page-title>Overview</h1></div>
            <div class="cb-top-actions">
                <button type="button" class="cb-button cb-button-secondary" data-open-deposit>
                    <svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg><span>Add balance</span>
                </button>
                <button type="button" class="cb-button cb-button-primary" data-open-order>
                    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span>New stock order</span>
                </button>
            </div>
        </header>

        <div class="cb-loading" data-loading><span></span><p>Loading your workspace…</p></div>
        <div class="cb-alert" data-alert hidden><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 17h.01"/></svg><span></span></div>

        <section class="cb-view is-active" data-section="overview" hidden>
            <section class="cb-metrics cb-overview-metrics">
                <article class="cb-balance-metric"><span class="cb-metric-icon"><svg viewBox="0 0 24 24"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M16 13h2"/></svg></span><span><small>Available balance</small><strong data-balance>Rp0</strong><em>Ready for stock orders</em></span></article>
                <article><span class="cb-metric-icon is-blue"><svg viewBox="0 0 24 24"><path d="M20 7h-9M14 17H5M17 3l4 4-4 4M8 13l-4 4 4 4"/></svg></span><span><small>Orders in progress</small><strong data-metric-progress>0</strong></span></article>
                <article><span class="cb-metric-icon is-amber"><svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span><span><small>Awaiting review</small><strong data-metric-review>0</strong></span></article>
                <article><span class="cb-metric-icon is-green"><svg viewBox="0 0 24 24"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></span><span><small>Shipped orders</small><strong data-metric-shipped>0</strong></span></article>
            </section>

            <section class="cb-insight-grid">
                <article class="cb-panel cb-chart-panel">
                    <header><div><small>Balance activity</small><h2>Credits and stock spending</h2></div><div class="cb-chart-legend"><span class="is-credit"><i></i><b data-chart-credit>Rp0</b> added</span><span class="is-spend"><i></i><b data-chart-spend>Rp0</b> spent</span></div></header>
                    <div class="cb-balance-chart" data-balance-chart aria-label="Balance activity chart"></div>
                </article>
                <article class="cb-panel cb-order-chart-panel">
                    <header><div><small>Order status</small><h2>Stock order mix</h2></div><button type="button" data-view-jump="orders">View all <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button></header>
                    <div class="cb-order-chart" data-order-chart></div>
                </article>
            </section>

            <section class="cb-grid cb-overview-bottom">
                <article class="cb-panel cb-panel-orders">
                    <header><div><small>Stock orders</small><h2>Recent activity</h2></div><button type="button" data-view-jump="orders">View all <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button></header>
                    <div class="cb-order-list" data-recent-orders></div>
                </article>
                <article class="cb-panel cb-pipeline-panel">
                    <header><div><small>Order flow</small><h2>What happens next</h2></div></header>
                    <ol class="cb-flow">
                        <li class="is-complete"><span><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg></span><div><strong>Balance payment</strong><small>Deducted when you submit</small></div></li>
                        <li><span><svg viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg></span><div><strong>Executive review</strong><small>Shipping is arranged</small></div></li>
                        <li><span><svg viewBox="0 0 24 24"><path d="M10 17h4V5H2v12h3M14 9h4l4 4v4h-3M8 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM16 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg></span><div><strong>Store fulfillment</strong><small>Label ready and picking starts</small></div></li>
                    </ol>
                </article>
            </section>
        </section>

        <section class="cb-view" data-section="orders" hidden>
            <div class="cb-section-intro"><div><span class="cb-eyebrow"><i></i> Stock purchasing</span><h2>Your orders</h2><p>Every order stays visible from balance payment through Store Ops fulfillment.</p></div><button type="button" class="cb-button cb-button-primary" data-open-order><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>New stock order</button></div>
            <div class="cb-filterbar" role="group" aria-label="Filter stock orders"><button type="button" class="is-active" data-order-filter="all">All</button><button type="button" data-order-filter="awaiting">Awaiting shipment</button><button type="button" data-order-filter="store">At Store Ops</button><button type="button" data-order-filter="done">Completed</button></div>
            <div class="cb-order-list cb-order-list-full" data-all-orders></div>
        </section>

        <section class="cb-view" data-section="balance" hidden>
            <div class="cb-section-intro"><div><span class="cb-eyebrow"><i></i> Prepaid purchasing</span><h2>Balance & deposits</h2><p>Submit payment proof, follow its review, and see every credit and order charge.</p></div><button type="button" class="cb-button cb-button-primary" data-open-deposit><svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>Add balance</button></div>
            <section class="cb-balance-summary"><div><small>Available balance</small><strong data-balance>Rp0</strong><span>Updates after an executive approves your payment.</span></div><div><small>Pending deposits</small><strong data-pending-total>Rp0</strong><span data-pending-copy>No requests waiting</span></div></section>
            <section class="cb-grid cb-balance-grid">
                <article class="cb-panel"><header><div><small>Requests</small><h2>Deposit history</h2></div></header><div class="cb-deposit-list" data-deposits></div></article>
                <article class="cb-panel"><header><div><small>Ledger</small><h2>Balance activity</h2></div></header><div class="cb-transaction-list" data-transactions></div></article>
            </section>
        </section>

        <section class="cb-view" data-section="settings" hidden>
            <div class="partner-settings-layout">
                <section class="partner-settings-group" aria-labelledby="cb-appearance-title">
                    <header class="partner-settings-group-head"><span>Personalization</span><h3 id="cb-appearance-title">Appearance</h3><p>Control how your workspace and browser tab look.</p></header>
                    <div class="partner-settings-list">
                        <article class="partner-settings-item">
                            <div class="partner-settings-item-copy"><strong>Theme</strong><span>Follow your device or keep this workspace light or dark.</span></div>
                            <div class="partner-settings-item-control"><div class="partner-theme-switch is-inline" data-theme-switch aria-label="Theme preference"><button type="button" data-theme-option="system">System</button><button type="button" data-theme-option="light">Light</button><button type="button" data-theme-option="dark">Dark</button></div></div>
                        </article>
                        <article class="partner-settings-item">
                            <div class="partner-settings-item-copy"><strong>Browser favicon</strong><span>Use separate icons that stay clear in light and dark mode.</span></div>
                            <div class="partner-settings-item-control">
                                <div class="partner-favicon-summary-visual" aria-hidden="true">
                                    <?php foreach (['light' => 'L', 'dark' => 'D'] as $faviconTheme => $faviconInitial): ?>
                                        <?php $favicon = $faviconSettings[$faviconTheme] ?? ['configured' => false, 'url' => '']; ?>
                                        <span class="partner-favicon-summary-preview <?php echo !empty($favicon['configured']) ? 'is-configured' : ''; ?>" data-favicon-summary-preview="<?php echo $faviconTheme; ?>"><img <?php if (!empty($favicon['configured'])): ?>src="<?php echo htmlspecialchars((string) ($favicon['url'] ?? ''), ENT_QUOTES); ?>"<?php endif; ?> alt="" <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>><b <?php echo !empty($favicon['configured']) ? 'hidden' : ''; ?>><?php echo $faviconInitial; ?></b></span>
                                    <?php endforeach; ?>
                                </div>
                                <span class="partner-settings-status" data-favicon-summary><?php echo htmlspecialchars($faviconSummary, ENT_QUOTES); ?></span>
                                <button type="button" class="admin-ghost-btn" data-open-favicon-modal>Manage</button>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="partner-settings-group" aria-labelledby="cb-regional-title">
                    <header class="partner-settings-group-head"><span>Regional</span><h3 id="cb-regional-title">Language &amp; time</h3><p>Set the language and local time used throughout this workspace.</p></header>
                    <form class="partner-settings-list" data-regional-settings-form>
                        <article class="partner-settings-item"><div class="partner-settings-item-copy"><strong>Language</strong><span>Changes interface text and regional number formatting.</span></div><div class="partner-settings-item-control"><select name="language" class="partner-settings-select" data-language-setting aria-label="Language"><?php foreach (jg_partner_preference_languages() as $languageCode => $languageLabel): ?><option value="<?php echo htmlspecialchars($languageCode, ENT_QUOTES); ?>" <?php echo $partnerPreferences['language'] === $languageCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($languageLabel, ENT_QUOTES); ?></option><?php endforeach; ?></select></div></article>
                        <article class="partner-settings-item"><div class="partner-settings-item-copy"><strong>Time zone</strong><span>Controls the time shown for orders, deposits, and balance activity.</span></div><div class="partner-settings-item-control"><select name="timezone" class="partner-settings-select" data-timezone-setting aria-label="Time zone"><?php foreach (jg_partner_preference_timezones() as $timezoneCode => $timezoneLabel): ?><option value="<?php echo htmlspecialchars($timezoneCode, ENT_QUOTES); ?>" <?php echo $partnerPreferences['timezone'] === $timezoneCode ? 'selected' : ''; ?>><?php echo htmlspecialchars($timezoneLabel, ENT_QUOTES); ?></option><?php endforeach; ?></select></div></article>
                        <p class="partner-settings-save-status" data-regional-settings-status aria-live="polite"></p>
                    </form>
                </section>

                <section class="partner-settings-group" aria-labelledby="cb-account-title">
                    <header class="partner-settings-group-head"><span>Account</span><h3 id="cb-account-title">Partner details</h3><p>Review the contact and delivery details attached to new stock orders.</p></header>
                    <div class="partner-settings-list">
                        <article class="partner-settings-item"><div class="partner-settings-item-copy"><strong>Contact information</strong><span data-settings-contact>Loading partner contact details…</span></div><div class="partner-settings-item-control"><span class="partner-settings-status" data-settings-sku-count>Loading approved products…</span></div></article>
                        <article class="partner-settings-item"><div class="partner-settings-item-copy"><strong>Password</strong><span>Choose a strong password you do not use elsewhere.</span></div><div class="partner-settings-item-control"><button type="button" class="admin-ghost-btn" data-open-password-modal>Change password</button></div></article>
                    </div>
                </section>
            </div>
        </section>
    </main>

    <div class="cb-backdrop" data-sidebar-backdrop hidden></div>

    <div class="cb-modal" data-deposit-modal hidden>
        <button type="button" class="cb-modal-backdrop" data-close-modal aria-label="Close"></button>
        <form class="cb-modal-card cb-deposit-card" data-deposit-form>
            <header><span class="cb-modal-icon"><svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span><div><small>Balance request</small><h2>Add funds</h2></div><button type="button" data-close-modal aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></header>
            <div class="cb-modal-body">
                <label class="cb-field"><span>Deposit amount</span><div class="cb-money-input"><small>Rp</small><input type="text" name="amount" inputmode="numeric" autocomplete="off" placeholder="5,000,000" required data-deposit-amount></div></label>
                <div class="cb-amount-presets"><button type="button" data-amount="1000000">Rp1m</button><button type="button" data-amount="2500000">Rp2.5m</button><button type="button" data-amount="5000000">Rp5m</button><button type="button" data-amount="10000000">Rp10m</button></div>
                <label class="cb-upload" data-proof-dropzone><input type="file" name="proof" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp" required hidden data-proof-input><span><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg></span><strong data-proof-name>Upload proof of payment</strong><small>PDF, PNG, JPG or WebP · maximum 10 MB</small></label>
                <div class="cb-review-note"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><p>Your request appears in the Executive Dashboard. Your balance changes only after approval.</p></div>
                <p class="cb-form-error" data-deposit-error hidden></p>
            </div>
            <footer><button type="button" class="cb-button cb-button-secondary" data-close-modal>Cancel</button><button type="submit" class="cb-button cb-button-primary">Send for review <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button></footer>
        </form>
    </div>

    <div class="cb-modal" data-order-modal hidden>
        <button type="button" class="cb-modal-backdrop" data-close-modal aria-label="Close"></button>
        <form class="cb-modal-card cb-order-card" data-order-form>
            <header><span class="cb-modal-icon"><svg viewBox="0 0 24 24"><path d="m7.5 4.27 9 5.15M21 8l-9 5-9-5M12 22V12"/></svg></span><div><small>Balance purchase</small><h2>New stock order</h2></div><button type="button" data-close-modal aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></header>
            <div class="cb-order-builder">
                <div class="cb-builder-main">
                    <section class="cb-builder-section"><div class="cb-builder-heading"><span>01</span><div><strong>Choose approved products</strong><small>Everything selected stays together in one order.</small></div></div><label class="cb-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg><input type="search" placeholder="Search product, flavor or SKU" data-product-search></label><div class="cb-product-table" data-product-table></div></section>
                    <section class="cb-builder-section"><div class="cb-builder-heading"><span>02</span><div><strong>Delivery profile</strong><small>Filled automatically from your partner profile.</small></div></div><div class="cb-delivery-profile"><div><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span><small>Full name</small><strong data-delivery-name>Not configured</strong></span></div><div><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><span><small>Email</small><strong data-delivery-email>Not configured</strong></span></div><div class="is-wide"><svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.8a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.33 1.85.56 2.81.69A2 2 0 0 1 22 16.9z"/></svg><span><small>Phone</small><strong data-delivery-phone>Not configured</strong></span></div><div class="is-wide"><svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0z"/><circle cx="12" cy="10" r="2"/></svg><span><small>Full address</small><strong data-delivery-address>Not configured</strong></span></div></div><p class="cb-delivery-note">Delivery details are managed from the partner profile workspace and saved automatically with every order.</p><label class="cb-field"><span>Order note <small>Optional</small></span><input type="text" name="notes" maxlength="300" placeholder="Delivery instructions or reference"></label></section>
                </div>
                <aside class="cb-order-summary"><div><small>Available balance</small><strong data-order-balance>Rp0</strong></div><section><header><span>Order summary</span><small data-summary-count>0 products</small></header><div data-summary-lines><p>No products selected yet.</p></div></section><div class="cb-summary-total"><span>Total</span><strong data-order-total>Rp0</strong></div><div class="cb-balance-check" data-balance-check><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg><span>Covered by your balance</span></div><p class="cb-form-error" data-order-error hidden></p><button type="submit" class="cb-button cb-button-primary" disabled data-submit-order>Pay from balance · Submit</button><small class="cb-submit-note">Funds are deducted immediately. The order then waits for shipment arrangement.</small></aside>
            </div>
        </form>
    </div>

    <div class="admin-modal-shell partner-settings-modal" data-favicon-modal hidden>
        <div class="admin-modal-backdrop" data-close-favicon-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="cb-favicon-title">
            <div class="admin-modal-head"><div><span class="admin-panel-kicker">Appearance</span><h3 id="cb-favicon-title">Browser favicon</h3></div><button type="button" class="admin-ghost-btn" data-close-favicon-modal>Close</button></div>
            <p class="partner-settings-modal-copy">Upload one icon for light mode and another for dark mode. Empty slots use the Jenang Gemi icon.</p>
            <div class="partner-favicon-grid" data-favicon-settings>
                <?php foreach (['light' => 'Light mode', 'dark' => 'Dark mode'] as $faviconTheme => $faviconLabel): ?>
                    <?php $favicon = $faviconSettings[$faviconTheme] ?? ['configured' => false, 'url' => '', 'name' => '']; ?>
                    <form class="partner-favicon-card" data-favicon-form data-favicon-theme="<?php echo $faviconTheme; ?>">
                        <div class="partner-favicon-preview <?php echo !empty($favicon['configured']) ? 'is-configured' : ''; ?>" data-favicon-preview><img <?php if (!empty($favicon['configured'])): ?>src="<?php echo htmlspecialchars((string) ($favicon['url'] ?? ''), ENT_QUOTES); ?>"<?php endif; ?> alt="" <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>><span data-favicon-empty <?php echo !empty($favicon['configured']) ? 'hidden' : ''; ?>>Empty</span></div>
                        <div class="partner-favicon-card-copy"><strong><?php echo $faviconLabel; ?></strong><span data-favicon-name><?php echo htmlspecialchars((string) ($favicon['name'] ?? '') ?: 'No custom favicon', ENT_QUOTES); ?></span></div>
                        <input type="file" name="favicon" accept=".png,.ico,image/png,image/x-icon" data-favicon-input hidden>
                        <div class="partner-favicon-actions"><button type="button" class="admin-ghost-btn" data-choose-favicon><?php echo !empty($favicon['configured']) ? 'Replace' : 'Upload'; ?></button><button type="button" class="admin-danger-btn" data-remove-favicon <?php echo empty($favicon['configured']) ? 'hidden' : ''; ?>>Remove</button></div>
                        <p class="admin-form-error" data-favicon-error hidden></p>
                    </form>
                <?php endforeach; ?>
            </div>
            <small class="partner-favicon-help">PNG or ICO, maximum 1 MB. PNG files must be square, from 16×16 to 1024×1024.</small>
        </div>
    </div>

    <div class="admin-modal-shell partner-settings-modal partner-password-modal" data-password-modal hidden>
        <div class="admin-modal-backdrop" data-close-password-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="cb-password-title">
            <div class="admin-modal-head"><div><span class="admin-panel-kicker">Security</span><h3 id="cb-password-title">Change password</h3></div><button type="button" class="admin-ghost-btn" data-close-password-modal>Close</button></div>
            <form class="admin-affiliate-editor" data-password-form>
                <p class="admin-table-note" data-password-reset-note hidden>Set a new password to finish unlocking this workspace.</p>
                <label class="admin-affiliate-field" data-current-password-field><span class="admin-control-label">Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                <label class="admin-affiliate-field"><span class="admin-control-label">New password</span><input type="password" name="new_password" autocomplete="new-password" minlength="8" required></label>
                <label class="admin-affiliate-field"><span class="admin-control-label">Confirm new password</span><input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
                <p class="admin-form-error" data-password-error hidden></p>
                <div class="admin-modal-actions"><button type="button" class="admin-ghost-btn" data-close-password-modal>Cancel</button><button type="submit" class="admin-primary-btn">Save Password</button></div>
            </form>
        </div>
    </div>

    <aside class="cb-detail-drawer" data-detail-drawer aria-hidden="true"><div data-order-detail></div></aside>
    <button type="button" class="cb-detail-backdrop" data-close-detail hidden aria-label="Close order details"></button>
    <div class="cb-toast" data-toast hidden><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg><span></span></div>
</div>
<script type="module" src="/class-b-dashboard/class-b-dashboard.js?v=<?php echo urlencode($jsVersion ?: '1'); ?>"></script>
</body>
</html>
