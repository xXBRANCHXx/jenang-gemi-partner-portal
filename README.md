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

## Notes

- Partner profile access currently reads from the executive dashboard partner registry endpoint, with `data/partners.json` as local fallback.
- Draft orders currently use local JSON storage in `data/orders.json`.
- The long-term design is for this repo to communicate with `jenang-gemi-store-ops` through APIs for SKU, product, stock, and order data.
