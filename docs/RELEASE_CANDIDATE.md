## Release Candidate (RC)

This RC packages stability checks and compatibility rules for v4.0.0.

### What is included
- Preflight command for production readiness
- Contract fixtures and compatibility guard
- Upgrade rules for semver and contracts_version

### Preflight
Run before deploy:

```
php tools/cli.php preflight
```

Optional flags:
- `--no-tests` to skip phpunit
- `--no-db` to skip db:check
- `--strict` to enable strict checks (when available)

### Ready for stable
- preflight reports all steps OK
- contract fixtures are up to date
- policy-check has no warnings in strict mode (if used)
- manual smoke checks for critical flows
