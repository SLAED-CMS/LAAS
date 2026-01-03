# Search

## Scopes

- Pages (frontend + admin)
- Media (admin)
- Users (admin)

## Query Rules

- Normalization: trim, collapse whitespace
- Minimum length: 2 characters (shorter → 422)
- Wildcards escaped: `%` and `_` with `ESCAPE '\'`
- Limit max: 50, page max: 1000

## Relevance Ordering

1. Prefix match (`q%`)
2. Contains match (`%q%`)
3. Newest records

## Indexes

- `pages.title`, `pages.slug`
- `media_files.original_name`, `media_files.mime_type`
- `users.username`, `users.email`

## UX (HTMX)

- Live search with debounce:
  - `hx-trigger="keyup changed delay:300ms"`
  - `hx-get` to the list endpoint
- Empty state uses `alert alert-secondary`
- Highlight uses `<mark>` with escaped segments
