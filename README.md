# Jenang Gemi Partner Portal

Partner-facing dashboard for `partner.jenanggemi.com`.

## Scope

- Partner login sequence
- Partner dashboard and session handling
- Partner order create/cancel/archive flow
- Catalog restrictions driven by admin partner profiles
- Future communication layer with store operations

## Current routes

- `/{partner_slug}/`
- `/{partner_slug}/dashboard/`
- `/{partner_slug}/logout/`
- `/{partner_slug}/api/session/`
- `/{partner_slug}/api/orders/`
- `/{partner_slug}/api/order-labels/`
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

If phpMyAdmin needs the tables created manually, import `database/partner-data-schema.sql` into `u558678012_Partner_Data`.

## Notes

- Partner profile access currently reads from the executive dashboard partner registry endpoint, with `data/partners.json` as local fallback.
- Partner-created orders use MySQL when configured, otherwise local JSON storage in `data/orders.json`. If MySQL is configured but unavailable, the portal now fails closed instead of silently switching storage backends.
- New partner-created orders require a shipment label PDF in the same create request, are saved with `status: IS_LISTED`, and become visible to Store Ops immediately.
- Partner order deadlines range from 12 to 48 hours and default to 24 hours. Partner orders can contain any number of approved SKU lines within normal server request limits.
- Partner pricing is stored and charged at the SKU level. ASTRA ratios affect inventory units only and do not multiply partner revenue.
- Partners can add up to 20 custom reseller/platform profiles in Settings. These options appear after Shopee and TikTok/Toped in new orders and receive separate platform metric cards in Analytics.
- Store Ops can read labeled partner orders from the token-protected `/api/store-orders/` feed, so direct database access is optional.
- Partner access is bound to unique partner URLs like `/{partner_slug}/`; one partner code cannot be used on another partner's landing page.
- Shipping-label PDFs are stored outside the public web root. Partner downloads require the owning session; Store Ops downloads use five-minute signed links from the existing order feed.
- Labels have a seven-day maximum lifetime, shortened to three days after fulfillment and one day after cancellation. Expired files are removed automatically whenever partners or Store Ops access the order flow; no external cron job is required.
- The dashboard's recent-order panel shows only unarchived orders from the last seven days. Archived orders remain restorable for 30 days, then are permanently removed during normal partner or Store Ops order reads.
- The sales chart supports preset ranges and a custom calendar-month view; the Overview metric cards follow the same selected window.
- Label uploads are PDF-only and limited to 10 MB.

## Production deployment order

1. Deploy this Partner Portal first. The first authenticated orders request adds the retention columns automatically.
2. Confirm `/api/db-status/` returns `{ "ok": true }` and an authenticated partner can load the dashboard.
3. Deploy the Executive Dashboard partner API hardening that narrows the public registry. Existing Partner Portal sessions are intentionally invalidated once and must sign in again.
4. Submit and fulfill one test order through Store Ops before inviting partners.
