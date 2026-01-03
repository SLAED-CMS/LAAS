# Backup & Restore

## Overview
- Backups are created via CLI and stored in `storage/backups/`.
- Restore is destructive and requires double confirmation.
- In production (`APP_ENV=prod`), restore is blocked unless `--force` is provided.

## Commands
```
php tools/cli.php backup:create [--db-driver=auto|mysqldump|pdo]
php tools/cli.php backup:inspect <file>
php tools/cli.php backup:restore <file> [--force] [--dry-run]
```

## Backup Drivers
### Database
- Primary: `mysqldump` (when available and DB driver is MySQL).
- Fallback: PDO export (deterministic SQL).
- Driver selection:
  - `--db-driver=auto` (default)
  - `--db-driver=mysqldump`
  - `--db-driver=pdo`

### Storage
- Local or S3/MinIO (selected storage disk).
- Only `uploads/` and thumbnails under `_cache` are included.

## Archive Structure
```
/metadata.json
/manifest.json
/db/dump.sql
/storage/<disk>/uploads/...
```

## Metadata & Integrity
- `metadata.json` includes:
  - `version`, `timestamp` (ISO8601)
  - `app_env`, `storage_disk`, `db_driver_used`
  - `checksum_db`, `checksum_manifest`
- `manifest.json` includes SHA-256 for all archive entries.
- `backup:inspect` validates all checksums.

## Restore Safety
- Double confirmation is required:
  1) Type `RESTORE`
  2) Type backup filename
- Lock file: `storage/backups/.restore.lock`
- `--dry-run` validates archive and checksums without changes.
- Automatic safety backup is created before restore.
- No partial restore:
  - Local: restore into temp, then atomic swap of `storage/uploads`.
  - S3: stage uploads, then apply to final keys.
- Any error aborts and attempts rollback using the safety backup.

## Troubleshooting
- `backup:inspect` fails → archive corrupted or incomplete.
- Restore locked → another restore is running (remove lock only if safe).
- In prod → use `--force` explicitly.

**Last updated:** January 2026
