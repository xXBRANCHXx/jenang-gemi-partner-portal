# Jenang Gemi Partner Portal

Partner-facing dashboard for `partner.jenanggemi.com`.

## Scope

- Partner login sequence
- Partner dashboard and session handling
- Partner order create/edit/delete flow
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
- New partner-created orders are saved with `status: IS_LISTED` so Store Ops can pull them into the live fulfillment queue.
- Store Ops can read partner orders from the token-protected `/api/store-orders/` feed, so direct database access is optional.
- Partner access is bound to unique partner URLs like `/{partner_slug}/`; one partner code cannot be used on another partner's landing page.
