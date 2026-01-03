# Upgrading LAAS CMS

## Policy

- **Patch releases** (x.y.Z): Safe upgrades, minimal risk, bug fixes only
- **Minor releases** (x.Y.0): New features, review changelog, backward compatible
- **Major releases** (X.0.0): Breaking changes, plan maintenance window, test thoroughly

---

## Standard Upgrade Flow

**Always follow this procedure:**

1. **Backup:** `php tools/cli.php backup:create`
2. **Pull code:** `git pull` or download release
3. **Dependencies:** `composer install --no-dev`
4. **Migrations:** `php tools/cli.php migrate:up`
5. **Cache:** `php tools/cli.php cache:clear && php tools/cli.php templates:warmup`
6. **Reload PHP-FPM:** `systemctl reload php8.x-fpm` (or `service php-fpm reload`)
7. **Sanity check:**
   - Health: `curl http://yourdomain.com/health`
   - Admin login: `/admin`
   - Key flows: test pages, media, search

---

## Version-Specific Upgrade Paths

### v1.x → v2.2.3 (Current Stable)

**Overview:** Major stability and maturity improvements.

**Key changes:**
- Contract tests protect architectural invariants
- RBAC diagnostics for permission introspection
- Global admin search
- Config export capability
- DevTools disabled in production (v2.0+)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review [docs/CONTRACTS.md](docs/CONTRACTS.md) for contract tests
3. Verify RBAC permissions: `php tools/cli.php rbac:status`
4. Test config export: `php tools/cli.php config:export`
5. Ensure `APP_DEBUG=false` in production `.env`

---

### v2.1.x → v2.2.x

**Changes:**
- RBAC diagnostics page (`/admin/diagnostics`)
- Contract tests for modules, storage, media
- Audit event for diagnostics views

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test diagnostics: `/admin/diagnostics` (requires `admin.access`)
3. Run contract tests: `vendor/bin/phpunit --testsuite contracts`

---

### v2.0.x → v2.1.x

**Changes:**
- `config:export` CLI command with sensitive data redaction
- Global admin search (`/admin/search`)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test config export: `php tools/cli.php config:export --output=config-snapshot.json`
3. Test global search: `/admin/search`

---

### v1.15.x → v2.0.0

**Breaking changes:**
- **DevTools disabled in production** (`APP_DEBUG=false` enforced)
- Production hardening enforced
- Architectural contracts stabilized

**Upgrade steps:**
1. **Staging test required**
2. Create backup
3. Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`
4. Follow standard upgrade flow
5. Verify DevTools is disabled in production
6. Run smoke tests: `php tools/cli.php ops:check`
7. Test backup/restore on staging

---

### v1.14.x → v1.15.x

**Changes:**
- Permission groups for RBAC
- Role cloning support
- Audit filters (user/action/date)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review permission groups in admin UI
3. Test audit filters: `/admin/audit`

---

### v1.13.x → v1.14.x

**Changes:**
- Search functionality (admin + frontend)
- HTMX live search with debounce

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test frontend search: `/search`
3. Test admin search: `/admin/pages`, `/admin/media`, `/admin/users`

---

### v1.12.x → v1.13.x

**Changes:**
- File cache for settings and menus
- Cache invalidation hooks
- Template warmup CLI

**Upgrade steps:**
1. Follow standard upgrade flow
2. Warm up templates: `php tools/cli.php templates:warmup`
3. Verify cache directory: `storage/cache/data/`

---

### v1.11.x → v1.12.x

**Changes:**
- GitHub Actions CI/CD
- Smoke tests: `ops:check`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Run smoke tests: `php tools/cli.php ops:check`

---

### v1.10.x → v1.11.x

**Changes:**
- `/health` endpoint
- Read-only maintenance mode (`APP_READ_ONLY`)
- Backup/restore CLI hardening

**Upgrade steps:**
1. Follow standard upgrade flow
2. Test health endpoint: `curl http://yourdomain.com/health`
3. Test backup: `php tools/cli.php backup:create`
4. Test inspect: `php tools/cli.php backup:inspect <file>`
5. Review [docs/BACKUP.md](docs/BACKUP.md)

---

### v1.9.x → v1.10.x

**Changes:**
- S3/MinIO storage support
- Public media access modes
- Signed URLs for temporary access

**Action required:**
- Review storage configuration in `config/media.php`
- Choose disk: `local` or `s3`
- If using S3: configure credentials in `.env`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review [docs/MEDIA.md](docs/MEDIA.md) storage section
3. Configure storage disk (default: local)
4. If using S3, set in `.env`:
   ```
   STORAGE_DISK=s3
   S3_REGION=us-east-1
   S3_BUCKET=your-bucket
   S3_KEY=your-key
   S3_SECRET=your-secret
   S3_ENDPOINT=https://s3.amazonaws.com  # or MinIO URL
   ```
5. Test media uploads and thumbnails

---

### v1.8.x → v1.9.x

**Changes:**
- Pre-generated thumbnails (sm/md/lg)
- Media picker (HTMX modal)
- Image hardening (max pixels, metadata stripping)

**Action required:**
- Generate thumbnails for existing media: `php tools/cli.php media:sync-thumbs` (if command exists)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Review thumbnail configuration in `config/media.php`
3. Test thumbnail serving: `/media/thumb/{hash}/md.{ext}`

---

### v1.7.x → v1.8.x

**Major changes:**
- **Media module introduced**
- Hardened upload pipeline (quarantine, MIME validation)
- SHA-256 deduplication
- ClamAV antivirus support (optional)

**Action required:**
- Review media security settings in `config/media.php`
- Configure ClamAV if needed (set `MEDIA_CLAMAV_ENABLED=true` in `.env`)

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant media permissions to roles:
   ```bash
   php tools/cli.php rbac:grant admin media.view
   php tools/cli.php rbac:grant admin media.upload
   php tools/cli.php rbac:grant admin media.delete
   ```
3. Review [docs/MEDIA.md](docs/MEDIA.md)
4. Test media uploads: `/admin/media`

---

### v1.6.x → v1.7.x

**Changes:**
- DevTools module (debug toolbar)
- Request/DB/Performance collectors

**Note:** DevTools is only visible when `APP_DEBUG=true`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant debug permission (dev only):
   ```bash
   php tools/cli.php rbac:grant youruser debug.view
   ```
3. Test DevTools panel (bottom of page when debug enabled)

---

### v1.5.x → v1.6.x

**Changes:**
- Menu system maturity
- Audit Log module

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant audit permission:
   ```bash
   php tools/cli.php rbac:grant admin audit.view
   ```
3. Test audit log: `/admin/audit`

---

### v1.0–v1.4 → v1.5+

**Changes:**
- Menu/Navigation module
- Validation layer introduced

**Upgrade steps:**
1. Follow standard upgrade flow
2. Sync modules: `php tools/cli.php module:sync`
3. Test menu rendering: `{% menu 'main' %}` in templates

---

### v0.x → v1.0+

**Major changes:**
- Admin UI introduced
- RBAC system
- DB-backed modules and settings

**Action required:**
- Ensure admin user exists and has `admin.access` permission
- Review all module states: `php tools/cli.php module:status`

**Upgrade steps:**
1. Follow standard upgrade flow
2. Grant admin access:
   ```bash
   php tools/cli.php rbac:grant admin admin.access
   ```
3. Access admin panel: `/admin`

---

## Breaking Changes by Version

### v2.0.0
- **DevTools disabled in production:** `APP_DEBUG=false` enforced
- Architectural contracts stabilized (no internal API changes allowed)

### v1.8.0
- **Media module required:** Old file handling (if any) must migrate to Media module
- RBAC permissions required for media operations

### v1.0.0
- **Admin UI introduced:** `/admin` route protection via RBAC
- DB-backed settings override `config/app.php`

---

## Rollback Strategy

### Code Rollback
```bash
# Git-based
git checkout v1.x.y
composer install --no-dev

# Manual
# Replace files with previous release archive
```

### Database Rollback
```bash
# Restore from backup
php tools/cli.php backup:restore storage/backups/backup-YYYYMMDD-HHMMSS.sql

# Confirm destruction prompt carefully!
```

**Warning:** Restore is **destructive** and **irreversible**. Always test on staging first.

### Storage Rollback
- Local storage: restore `storage/media/` directory from backup archive
- S3 storage: use S3 versioning or restore from S3 backup

---

## Troubleshooting

### Migration fails
1. **Stop immediately** — do not continue
2. Check logs: `storage/logs/app-YYYY-MM-DD.log`
3. Identify failing migration in error message
4. Restore database from backup: `backup:restore`
5. Fix issue (schema conflict, missing dependency)
6. Re-run: `migrate:up`

### Cache issues after upgrade
```bash
# Clear all caches
php tools/cli.php cache:clear
php tools/cli.php templates:clear

# Warmup
php tools/cli.php templates:warmup
```

### Permission issues after upgrade
```bash
# Check RBAC status
php tools/cli.php rbac:status

# Grant missing permissions
php tools/cli.php rbac:grant <username> <permission>

# Review diagnostics
# Visit: /admin/diagnostics (v2.2+)
```

### Health check fails
```bash
# Run ops check
php tools/cli.php ops:check

# Check database
php tools/cli.php db:check

# Review config
php tools/cli.php config:export
```

### Media issues after upgrade
```bash
# Sync module state
php tools/cli.php module:sync

# Ensure Media module is enabled
php tools/cli.php module:enable Media

# Grant media permissions
php tools/cli.php rbac:grant admin media.view
php tools/cli.php rbac:grant admin media.upload
```

---

## Testing Upgrades

### Staging environment
1. Clone production database
2. Clone production storage
3. Apply upgrade on staging
4. Test all critical flows:
   - Login/logout
   - Page creation/editing
   - Media upload/delete
   - User management
   - Search functionality
5. Run smoke tests: `ops:check`
6. Only then upgrade production

### Production upgrade checklist
- [ ] Backup created and verified
- [ ] Maintenance window scheduled
- [ ] Staging tested successfully
- [ ] Team notified
- [ ] Rollback plan prepared
- [ ] Monitoring ready
- [ ] Post-upgrade validation planned

---

## References

- [Version History](docs/VERSIONS.md) — Technical changelog
- [Release Notes](docs/RELEASE.md) — Human-readable history
- [Roadmap](docs/ROADMAP.md) — Project evolution (v0.1 → v2.x)
- [Production Guide](docs/PRODUCTION.md) — Deployment best practices
- [Backup Guide](docs/BACKUP.md) — Backup/restore procedures
- [Limitations](docs/LIMITATIONS.md) — Known constraints

---

**Last updated:** January 2026 (v2.2.3)
