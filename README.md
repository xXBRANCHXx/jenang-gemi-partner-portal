# Jenang Gemi Partner Portal

Partner-facing dashboard for `partner.jenanggemi.com`.

## Scope

- Partner login sequence
- Partner dashboard and session handling
- Draft order create/edit/delete flow
- Catalog restrictions driven by admin partner profiles
- Future communication layer with store operations

## Current routes

- `/dashboard/`
- `/logout/`
- `/api/session/`
- `/api/orders/`

## Database setup

The partner order tables are created automatically when the portal can connect to MySQL. Database credentials must be configured on the server and should not be committed to git.

1. Deploy the repo. `.cpanel.yml` creates `config.local.php` from `config.local.placeholder.php` only if `config.local.php` does not already exist.
2. In Hostinger File Manager, edit `config.local.php` and replace `PUT_DATABASE_PASSWORD_HERE`.
3. Visit `/dashboard/` or `/api/orders/` while logged in as a partner; this triggers automatic table creation.

`config.local.php` is ignored by git. Future deploys should not overwrite it because the deploy task checks that the file is missing before copying the placeholder.

If phpMyAdmin needs the tables created manually, import `database/partner-data-schema.sql` into `u558678012_Partner_Data`.

## Notes

- Partner profile access currently reads from the executive dashboard partner registry endpoint, with `data/partners.json` as local fallback.
- Draft orders use MySQL when configured, otherwise local JSON storage in `data/orders.json`.
- The long-term design is for this repo to communicate with `jenang-gemi-store-ops` through APIs for SKU, product, stock, and order data.
