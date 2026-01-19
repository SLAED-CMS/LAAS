# Blocks (MVP)

Blocks are the canonical content model for pages. Each block:
- has a `type`
- has a `data` payload
- is validated via the BlockRegistry allowlist

## Core Blocks

- `rich_text`
  - fields: `html` (sanitized)
- `image`
  - fields: `media_id`, `alt`, `caption` (optional)
- `cta`
  - fields: `label`, `url`, `style` (optional)

## Rendering

- HTML rendering happens via the theme using a list of rendered block fragments.
- JSON rendering returns structured blocks 1:1 for headless usage.

## Validation

Blocks are strictly validated. Unknown block types or invalid data reject the save.
