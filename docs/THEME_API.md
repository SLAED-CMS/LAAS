# Theme API

## Purpose / Goals
- Define a stable contract for theme capabilities and structure.
- Make validation deterministic and CI-friendly.
- Keep rendering rules explicit and auditable.

## Contract (theme.json)
Required fields:
- `name` (string)
- `version` (semver)
- `api` (string, must be `v2`)

Optional fields:
- `capabilities` (array<string>)
- `provides` (array<string>)
- `meta` (object)

## Theme Structure
Required files:
- `themes/<theme>/theme.json`
- `themes/<theme>/layouts/base.html`
- `themes/<theme>/partials/header.html`

Recommended directories:
- `layouts/*`
- `pages/*`
- `partials/*`

## Provided variables
- `app.name`, `app.version`, `app.env`, `app.debug`
- `user.id`, `user.username`, `user.roles`
- `csrf_token`
- `assets` and asset helpers
- `locale`
- `t()` (i18n)

## UI Tokens contract
- UI tokens are mapped by controllers and views.
- Tokens are stable and must not leak presentation logic into services.
- Enforced by controller boundary tests and token mappers.

## Slots & blocks
- Required slot: `content`
- Optional slots: `header`, `footer`, `sidebar`

## HTMX rules
- `hx-*` attributes live in templates only.
- HTMX requests render partials (no layout).

## Forbidden
- Inline style
- Inline JS
- CDN usage in templates

## Migration notes
- Changes to `theme.json` require snapshot acceptance.
- Validation runs via `php tools/cli.php themes:validate`.
