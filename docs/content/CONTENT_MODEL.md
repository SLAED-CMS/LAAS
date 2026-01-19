# Content Model (MVP)

Pages use a revisioned block model:

- Table: `pages_revisions`
- Field: `blocks_json` (canonical format)
- Pages always render the latest revision

Legacy `pages.content` remains untouched and is used as a fallback when no
revision exists. No migration is performed.

## Save / Load

- Admin save accepts `blocks_json` directly.
- Admin load returns the latest revision for edit flows.

## Headless

- JSON responses include the structured blocks.
- No theme coupling in JSON output.
