# Audit Log

## Filters

Admin audit UI supports filters:

- user (username or user_id)
- action
- date range (from/to)

Filters are applied on the backend and kept in pagination URLs.

## Date Range

- Dates use `YYYY-MM-DD`.
- Invalid ranges return HTTP 422.
