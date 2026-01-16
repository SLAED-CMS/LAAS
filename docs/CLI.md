# CLI Reference

Complete reference for LAAS CMS command-line tools.

---

## Quick Reference

```bash
php tools/cli.php <command> [options]
```

---

## Cache Management

| Command | Description |
|---------|-------------|
| `cache:clear` | Clear all cache |
| `cache:status` | Show cache config/status |
| `cache:prune` | Remove stale cache files (uses `CACHE_TTL_DAYS`) |
| `templates:clear` | Clear template cache |
| `templates:warmup` | Warmup template cache |

---

## Database & Migrations

| Command | Description |
|---------|-------------|
| `db:check` | Check database connection |
| `migrate:status` | Show migration status |
| `migrate:up` | Run pending migrations |
| `db:migrations:analyze` | Analyze pending migrations (JSON) |
| `db:indexes:audit --json` | Audit required indexes |

---

## Settings

| Command | Description |
|---------|-------------|
| `settings:get KEY` | Get setting value |
| `settings:set KEY VALUE --type=string\|int\|bool\|json` | Set setting value |

---

## Operations

| Command | Description |
|---------|-------------|
| `ops:check` | Run production smoke tests |
| `config:export [--output=file.json]` | Export runtime config snapshot |
| `session:smoke` | Session driver smoke test |
| `security:reports:prune --days=14` | Prune CSP/security reports |
| `doctor` | Run preflight (no tests) + environment hints |
| `preflight [--no-tests] [--no-db] [--strict]` | Pre-deployment checks |

---

## Backup & Restore

| Command | Description |
|---------|-------------|
| `backup:create [--include-media=1] [--include-db=1]` | Create backup v2 |
| `backup:verify <file>` | Verify backup file |
| `backup:inspect <file>` | Inspect backup metadata |
| `backup:restore <file> [--dry-run=1] [--force=1]` | Restore from backup (destructive) |
| `backup:prune --keep=10` | Prune old backups |

---

## Media Operations

| Command | Description |
|---------|-------------|
| `media:gc [--disk=<name>] [--dry-run=1] [--mode=orphans\|retention\|all] [--limit=N]` | Cleanup orphans/retention |
| `media:verify [--disk=<name>] [--limit=N]` | Verify DB -> storage consistency |

---

## RBAC

| Command | Description |
|---------|-------------|
| `rbac:status` | Show RBAC status |
| `rbac:grant <username> <permission>` | Grant permission |
| `rbac:revoke <username> <permission>` | Revoke permission |

---

## Modules

| Command | Description |
|---------|-------------|
| `module:status` | Show module status |
| `module:sync` | Sync modules to database |
| `module:enable <Name>` | Enable module |
| `module:disable <Name>` | Disable module |

> [!NOTE]
> `System` and `Api` modules are protected from disable.

---

## AI (v4.0.0)

| Command | Description |
|---------|-------------|
| `ai:doctor` | AI subsystem diagnostics |
| `ai:proposal:apply <id> --yes` | Apply saved proposal |
| `ai:plan:run <plan> --yes` | Run plan workflow |
| `ai:plan:demo` | Demo plan workflow |
| `templates:raw:scan` | List raw usage in themes |
| `templates:raw:check --path=themes` | Allowlist baseline check |
| `content:sanitize-pages --yes` | Sanitize legacy content |

---

## Policy & Contracts (CI)

| Command | Description |
|---------|-------------|
| `policy:check` | Run policy checks (CI guardrails) |
| `contracts:check` | Check contracts |
| `contracts:fixtures:check` | Check contract fixtures |
| `contracts:fixtures:dump --force` | Dump contract fixtures |
| `contracts:snapshot:update` | Update contract snapshot |
| `release:check` | Pre-release validation |
| `theme:validate` | Validate theme structure |

---

## QA Quick Commands

Before commit:
```bash
php tools/cli.php policy:check && vendor/bin/phpunit
```

Full QA:
```bash
php tools/cli.php policy:check
php tools/cli.php contracts:fixtures:check
php tools/cli.php contracts:check
vendor/bin/phpunit
```

v4.0.0 release:
```bash
php tools/cli.php templates:raw:check --path=themes
php tools/cli.php ai:doctor
php tools/cli.php ai:plan:demo
vendor/bin/phpunit
```

---

## Environment Variables

### Cache
- `CACHE_ENABLED` — Enable/disable cache
- `CACHE_DEFAULT_TTL` — Default TTL in seconds
- `CACHE_TAG_TTL` — Tag TTL
- `CACHE_TTL_DAYS` — Days for prune command
- `CACHE_DEVTOOLS_TRACKING` — Track cache in DevTools

### Performance Budgets
- `PERF_BUDGET_ENABLED` — Enable performance budgets
- `PERF_BUDGET_TOTAL_MS_WARN` — Total time warn threshold
- `PERF_BUDGET_SQL_COUNT_WARN` — SQL count warn threshold
- `PERF_BUDGET_SQL_MS_WARN` — SQL time warn threshold
- `PERF_BUDGET_TOTAL_MS_HARD` — Total time hard limit
- `PERF_BUDGET_SQL_COUNT_HARD` — SQL count hard limit
- `PERF_BUDGET_SQL_MS_HARD` — SQL time hard limit
- `PERF_BUDGET_HARD_FAIL` — Return 503 on hard limit breach

### Performance Guards
- `PERF_GUARDS_ENABLED` — Enable guards
- `PERF_GUARD_MODE` — `warn` or `block`
- `PERF_DB_MAX_QUERIES` — Max DB queries
- `PERF_DB_MAX_UNIQUE` — Max unique queries
- `PERF_DB_MAX_TOTAL_MS` — Max total DB time
- `PERF_HTTP_MAX_CALLS` — Max HTTP calls
- `PERF_HTTP_MAX_TOTAL_MS` — Max HTTP time
- `PERF_TOTAL_MAX_MS` — Max total request time
- `PERF_DB_MAX_QUERIES_ADMIN` — Admin GET override
- `PERF_TOTAL_MAX_MS_ADMIN` — Admin GET override
- `PERF_GUARD_EXEMPT_PATHS` — Exempt paths
- `PERF_GUARD_EXEMPT_ROUTES` — Exempt routes

### Database Safety
- `DB_MIGRATIONS_SAFE_MODE` — `warn` or `block`
- `ALLOW_DESTRUCTIVE_MIGRATIONS` — Allow destructive migrations

---

## See Also

- [Production Guide](PRODUCTION.md)
- [Backup Guide](BACKUP.md)
- [Testing](TESTING.md)
- [RBAC](RBAC.md)