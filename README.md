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

1. Copy `config.local.php.example` to `config.local.php` on the deployed server.
2. Fill in the MySQL database name, user, and password.
3. Put the file in either the project root or `/public_html/config.local.php`.
4. Visit `/dashboard/` or `/api/orders/` while logged in as a partner; this triggers automatic table creation.

If phpMyAdmin needs the tables created manually, import `database/partner-data-schema.sql` into `u558678012_Partner_Data`.

## Notes

- Partner profile access currently reads from the executive dashboard partner registry endpoint, with `data/partners.json` as local fallback.
- Draft orders use MySQL when configured, otherwise local JSON storage in `data/orders.json`.
- The long-term design is for this repo to communicate with `jenang-gemi-store-ops` through APIs for SKU, product, stock, and order data.
