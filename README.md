# Jenang Gemi Partner Portal

Partner management backend for `partner.jenanggemi.com`.

## Scope

- Partner profile management
- Company assignment (`Jenang Gemi`, `ZERO`, `ZFIT`)
- Brand and product access controls per partner
- Pricing agreements per partner
- Future communication layer between executive admin and store operations

## Current routes

- `/dashboard/`
- `/profiles/`
- `/profile/?code=...`
- `/logout/`
- `/api/partners/`

## Notes

- Partner profile data currently uses local JSON storage in `data/partners.json`.
- The long-term design is for this repo to communicate with `jenang-gemi-store-ops` through APIs for SKU, product, stock, and order data.
