# Theme API v1

## Overview

Theme API v1 standardizes theme metadata, layout mapping, and global template variables.
It is backward-compatible with existing themes that only provide `layout.html`.

## theme.json

Location: `themes/<theme>/theme.json`

Required fields:
- `name`
- `version`
- `author`
- `layouts.base` (path to base layout within the theme)

Optional fields:
- `layouts.admin`
- `assets_profile` (`default` or `admin`)

Example:
```json
{
  "name": "default",
  "version": "1.0.0",
  "author": "LAAS CMS",
  "layouts": {
    "base": "layouts/base.html",
    "admin": "layouts/admin.html"
  },
  "assets_profile": "default"
}
```

## Theme structure (v1)

Standard structure:
- `layouts/`
- `pages/`
- `partials/`

Module overrides are allowed but not required.
Legacy themes without `theme.json` continue to work.

## Layout mapping

`layouts.base` is required and validated at runtime when `theme.json` exists.
If `theme.json` is missing, the system falls back to `layout.html`.

## Global template variables

Guaranteed globals:
- `app.name`
- `app.version`
- `app.env`
- `user.id`
- `user.username`
- `user.roles`
- `csrf_token`
- `locale`
- `t()` (translator helper)
- `assets` (asset manager instance)

`flash` is available when provided by controller data.

## Compatibility

- No changes required for existing templates.
- `layout.html` remains valid.
- Theme API v1 adds metadata for future theme tooling.
