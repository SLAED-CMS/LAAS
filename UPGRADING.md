# Upgrading LAAS CMS

## Policy
- Patch releases: safe upgrades, minimal risk.
- Minor releases: new features, review changelog.
- Major releases: breaking changes, plan maintenance window.

## Standard upgrade flow
1) Create backup: `backup:create`
2) Pull code
3) Install dependencies: `composer install`
4) Run migrations: `migrate:up`
5) Sanity check: `/health`, admin login, key flows

## Upgrade paths
### 1.10 → 1.11
- Review ops features (health, read-only, backup/restore).
- Validate storage disk configuration (local/s3).

### 1.11.x → 1.11.y
- Follow standard upgrade flow.
- Check `docs/VERSIONS.md` for notes.

### 1.x → 2.0
- Expect breaking changes.
- Schedule maintenance window.
- Test on staging before production.

## Breaking changes
- Check `docs/VERSIONS.md` and release notes.
- Identify config changes and migration impacts.
- Prepare rollback before applying.

## Rollback strategy
- Code rollback: revert to previous release.
- DB rollback: restore from backup.
- Storage rollback: restore uploads from backup archive.

## If migration fails
- Stop the process.
- Check logs for the failing migration.
- Restore DB from backup if needed.
- Re-run `migrate:up` after fixing the issue.
