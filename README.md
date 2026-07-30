# Jenang Gemi Partner Portal

Partner-facing dashboard for `partner.jenanggemi.com`.

## Scope

- Partner login sequence
- Partner dashboard and session handling
- Partner order create/cancel/archive flow
- Seven-day partner bills, payment-proof review, and order-level disputes
- Localized, print-ready partner performance PDF reports
- Catalog restrictions driven by admin partner profiles
- Future communication layer with store operations

## Current routes

- `/{partner_slug}/`
- `/{partner_slug}/dashboard/`
- `/{partner_slug}/logout/`
- `/{partner_slug}/api/session/`
- `/{partner_slug}/api/orders/`
- `/{partner_slug}/api/order-labels/`
- `/{partner_slug}/api/reports/`
- `/{partner_slug}/api/billing/`
- `/api/session/`
- `/api/orders/`
- `/api/store-orders/`
- `/api/db-status/`

## Database setup

The partner order tables are created automatically when the portal can connect to MySQL. Database credentials must be configured on the server and should not be committed to git.

1. Deploy the repo. `.cpanel.yml` creates `config.local.php` from `config.local.placeholder.php` only if `config.local.php` does not already exist.
2. In Hostinger File Manager, edit `config.local.php` and replace `PUT_DATABASE_PASSWORD_HERE`.
3. Visit `/{partner_slug}/dashboard/` or `/{partner_slug}/api/orders/` while logged in as a partner; this triggers automatic table creation.

`config.local.php` is ignored by git. Future deploys should not overwrite it because the deploy task checks that the file is missing before copying the placeholder.

If phpMyAdmin needs the tables created manually, import `database/partner-data-schema.sql` and then `database/partner-billing-schema.sql` into `u558678012_Partner_Data`.

## Notes

- Partner profile access currently reads from the executive dashboard partner registry endpoint, with `data/partners.json` as local fallback.
- Partner-created orders use MySQL when configured, otherwise local JSON storage in `data/orders.json`. If MySQL is configured but unavailable, the portal now fails closed instead of silently switching storage backends.
- New partner-created orders require a shipment label PDF in the same create request, are saved with `status: IS_LISTED`, and become visible to Store Ops immediately.
- Partner order deadlines range from 12 to 48 hours and default to 24 hours. Partner orders can contain any number of approved SKU lines within normal server request limits.
- Partner pricing is stored and charged at the SKU level. ASTRA ratios affect inventory units only and do not multiply partner revenue.
- Partners can add up to 20 custom reseller/platform profiles in Settings. These options appear after Shopee and TikTok/Toped in new orders, receive separate platform metric cards in Analytics, and color-code the stacked Sales window chart.
- Store Ops can read labeled partner orders from the token-protected `/api/store-orders/` feed, so direct database access is optional.
- Listed and accepted orders remain in the Store Ops feed until an operator confirms that the shipping label printed; cancelled and fulfilled orders are excluded.
- Partner access is bound to unique partner URLs like `/{partner_slug}/`; one partner code cannot be used on another partner's landing page.
- Shipping-label PDFs are stored outside the public web root. Partner downloads require the owning session; Store Ops downloads use five-minute signed links from the existing order feed.
- Labels have a seven-day maximum lifetime, shortened to three days after fulfillment and one day after cancellation. Expired files are removed automatically whenever partners or Store Ops access the order flow; no external cron job is required.
- The dashboard's recent-order panel shows only unarchived orders from the last seven days. Archived orders remain restorable for 30 days, then are permanently removed during normal partner or Store Ops order reads.
- The sales chart supports today, 7-day, 30-day, year, and all-time presets plus checked calendar-month and custom start/end-date modes. Today follows the calendar day, month and custom ranges include every calendar day, and the Overview metric cards follow the same selected window.
- The Reports page creates professional A4 PDFs for custom periods with an executive summary and optional channel, product, and order-ledger sections. Reports prioritize the partner identity, use the configured light or dark favicon when available, and otherwise render a partner-initial profile mark. PDF language, dates, number formatting, and timezone follow each partner's Regional Settings; cancelled orders remain auditable but are excluded from sales units and partner cost.
- Label uploads are PDF-only and limited to 10 MB.
- Billing periods are fixed seven-day blocks anchored on July 1, 2026. Closed bills are due three days after the period ends. Proofs accept PDF, PNG, JPEG, GIF, or WebP files up to 10 MB and are stored privately in MySQL so the Executive Dashboard can review them without a public file URL.
- The Billing navigation `NEW` marker remains visible for seven full days from the partner's first post-launch dashboard load; opening Billing does not dismiss it. The automatic tutorial runs once per browser/device for each partner account and remains manually reopenable. Language, number formatting, dates, and tutorial copy follow the partner's existing regional preferences.
- An order-level dispute only changes the selected bill items. Finance can accept the dispute, or reject it with a reason and optional evidence image that remains visible in the partner's bill detail.

## Production deployment order

1. Deploy this Partner Portal first. The first authenticated Billing request creates the weekly billing tables and adds the order billing columns automatically.
2. Confirm `/api/db-status/` returns `{ "ok": true }` and an authenticated partner can load the dashboard.
3. Deploy the Executive Dashboard partner API hardening that narrows the public registry. Existing Partner Portal sessions are intentionally invalidated once and must sign in again.
4. Submit and fulfill one test order through Store Ops before inviting partners.
