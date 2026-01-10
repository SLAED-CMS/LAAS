# Assets (local vendor)

## Goal
- Store all third-party assets locally under `public/assets/vendor/*`.
- Keep templates free of CDN links and inline scripts/styles.

## Required vendor layout
```
public/assets/vendor/
  bootstrap/5.3.3/bootstrap.min.css
  bootstrap/5.3.3/bootstrap.bundle.min.js
  htmx/1.9.12/htmx.min.js
  bootstrap-icons/1.11.3/bootstrap-icons.css
  bootstrap-icons/1.11.3/fonts/
```

## How to populate vendor files
1) Download the matching versions from official project releases.
2) Put files into the paths above (keep the version folders).
3) If bootstrap-icons include fonts, keep them in the `fonts/` subfolder.

## Updating versions
1) Update `.env` values:
   - `ASSET_BOOTSTRAP_VERSION`
   - `ASSET_HTMX_VERSION`
   - `ASSET_BOOTSTRAP_ICONS_VERSION`
2) Replace vendor files under `public/assets/vendor/*`.
3) Validate policy:
   - `php tools/policy-check.php`
