# Release

## Process

- Update `docs/VERSIONS.md`
- Run CI (lint, tests, smoke, release-check)
- Tag: `v2.0.0`
- Push tag to create GitHub Release automatically

## Production Checklist

- See `docs/PRODUCTION.md`
- Ensure `APP_ENV=prod`, `APP_DEBUG=false`, `DEVTOOLS_ENABLED=false`
- Verify `/health` returns 200
- Run `php tools/cli.php release:check`

## Rollback

- See `UPGRADING.md` and `docs/BACKUP.md`
- Keep a fresh backup before tagging
