# Theme API v2

## Motivation

Theme API v2 makes theme metadata explicit, validated, and capability-driven. The goal
is a stable contract for theme metadata without changing runtime rendering.

## Contract (theme.json)

Required fields:
- `name` (string)
- `version` (semver)
- `api` (string, must be `v2`)

Optional fields:
- `capabilities` (array<string>)
- `provides` (array<string>)
- `meta` (object)

Schema: `docs/theme/theme.schema.json` (JSON Schema draft-07).

## Capabilities

Allowlisted capabilities:
- `toasts`
- `devtools`
- `headless`
- `blocks`

If a theme does not declare a capability, the feature is treated as disabled.

## Upgrade v1 -> v2

1) Add `"api": "v2"` to `theme.json`.
2) Add a `capabilities` array for features the theme supports.
3) Keep existing fields like `layouts` and `assets_profile` (still supported).
4) Run `php tools/cli.php themes:validate` to verify.

## Validation & Policy

- `themes:validate` validates all themes.
- `policy:check` includes Theme API validation.
- `theme.json` changes are snapshot-frozen and must be explicitly accepted.
