<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/partner-auth.php';

if (!jg_partner_is_authenticated()) {
    header('Location: /');
    exit;
}

$partner = jg_partner_current_profile();
$partnerName = trim((string) ($partner['name'] ?? 'Partner')) ?: 'Partner';
$partnerSlug = jg_partner_profile_slug($partner);
$base = $partnerSlug !== '' ? '/' . rawurlencode($partnerSlug) : '';
$cssVersion = (string) @filemtime(__DIR__ . '/class-b-dashboard.css');
$jsVersion = (string) @filemtime(__DIR__ . '/class-b-dashboard.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?> · Stock Partner</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/class-b-dashboard/class-b-dashboard.css?v=<?php echo urlencode($cssVersion ?: '1'); ?>">
</head>
<body>
<div class="cb-app" data-class-b-dashboard
     data-api="<?php echo htmlspecialchars($base . '/api/class-b/', ENT_QUOTES); ?>"
     data-session-api="<?php echo htmlspecialchars($base . '/api/session/', ENT_QUOTES); ?>"
     data-logout="<?php echo htmlspecialchars($base . '/logout/', ENT_QUOTES); ?>"
     data-csrf="<?php echo htmlspecialchars(jg_partner_csrf_token(), ENT_QUOTES); ?>">
    <aside class="cb-sidebar" data-sidebar>
        <a class="cb-brand" href="<?php echo htmlspecialchars($base . '/dashboard/', ENT_QUOTES); ?>" aria-label="Partner dashboard home">
            <span class="cb-brand-mark" aria-hidden="true"><i></i><i></i></span>
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
        </nav>

        <div class="cb-sidebar-profile">
            <span class="cb-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($partnerName, 0, 1)), ENT_QUOTES); ?></span>
            <span><strong><?php echo htmlspecialchars($partnerName, ENT_QUOTES); ?></strong><small>Class B partner</small></span>
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
            <section class="cb-balance-hero">
                <div>
                    <span class="cb-eyebrow"><i></i> Available to spend</span>
                    <strong data-balance>Rp0</strong>
                    <p>Approved funds are ready for your next stock order.</p>
                </div>
                <div class="cb-hero-actions">
                    <button type="button" data-open-deposit><svg viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>Add funds</button>
                    <button type="button" data-open-order><svg viewBox="0 0 24 24"><path d="M3 6h18M6 6l1 14h10l1-14M9 6V4h6v2M9 11v5M15 11v5"/></svg>Order stock</button>
                </div>
                <svg class="cb-hero-art" viewBox="0 0 300 160" aria-hidden="true"><circle cx="247" cy="25" r="90"/><circle cx="225" cy="141" r="54"/><path d="M164 32h91v91h-91z"/></svg>
            </section>

            <section class="cb-metrics">
                <article><span class="cb-metric-icon is-blue"><svg viewBox="0 0 24 24"><path d="M20 7h-9M14 17H5M17 3l4 4-4 4M8 13l-4 4 4 4"/></svg></span><span><small>Orders in progress</small><strong data-metric-progress>0</strong></span></article>
                <article><span class="cb-metric-icon is-amber"><svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></span><span><small>Awaiting review</small><strong data-metric-review>0</strong></span></article>
                <article><span class="cb-metric-icon is-green"><svg viewBox="0 0 24 24"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></span><span><small>Shipped orders</small><strong data-metric-shipped>0</strong></span></article>
            </section>

            <section class="cb-grid">
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
                    <section class="cb-builder-section"><div class="cb-builder-heading"><span>02</span><div><strong>Confirm delivery details</strong><small>These details are saved with this order.</small></div></div><div class="cb-contact-grid"><label class="cb-field"><span>Full name</span><input type="text" name="recipient_name" maxlength="160" required></label><label class="cb-field"><span>Email</span><input type="email" name="recipient_email" maxlength="190" required></label><label class="cb-field"><span>Phone</span><input type="tel" name="recipient_phone" maxlength="64" required></label><label class="cb-field is-wide"><span>Full address</span><textarea name="recipient_address" maxlength="2000" rows="3" required></textarea></label><label class="cb-field is-wide"><span>Order note <small>Optional</small></span><input type="text" name="notes" maxlength="300" placeholder="Delivery instructions or reference"></label></div></section>
                </div>
                <aside class="cb-order-summary"><div><small>Available balance</small><strong data-order-balance>Rp0</strong></div><section><header><span>Order summary</span><small data-summary-count>0 products</small></header><div data-summary-lines><p>No products selected yet.</p></div></section><div class="cb-summary-total"><span>Total</span><strong data-order-total>Rp0</strong></div><div class="cb-balance-check" data-balance-check><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg><span>Covered by your balance</span></div><p class="cb-form-error" data-order-error hidden></p><button type="submit" class="cb-button cb-button-primary" disabled data-submit-order>Pay from balance · Submit</button><small class="cb-submit-note">Funds are deducted immediately. The order then waits for shipment arrangement.</small></aside>
            </div>
        </form>
    </div>

    <aside class="cb-detail-drawer" data-detail-drawer aria-hidden="true"><div data-order-detail></div></aside>
    <button type="button" class="cb-detail-backdrop" data-close-detail hidden aria-label="Close order details"></button>
    <div class="cb-toast" data-toast hidden><svg viewBox="0 0 24 24"><path d="m6 12 4 4 8-8"/></svg><span></span></div>
</div>
<script type="module" src="/class-b-dashboard/class-b-dashboard.js?v=<?php echo urlencode($jsVersion ?: '1'); ?>"></script>
</body>
</html>
