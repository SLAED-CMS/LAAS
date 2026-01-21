# Architectural Rules (One Pager)

This is the enforced boundary contract. It is short by design. If you change an area here, update or add the enforcing test/tool.

## Controller boundaries
- Controllers are HTTP-only: no repositories, no DatabaseManager, no SQL.
- Controllers depend on service interfaces, not concrete services.
- GET/HEAD-only controllers depend on Read interfaces only.
- Enforced by: `ControllerNoRepositoryDeps`, `ControllerNoNewService`, `ControllerGetOnlyNoWriteDeps`.

## Service interfaces and read/write split
- Each `*Service` exposes a `*ServiceInterface`.
- Split read and write: `*ReadServiceInterface` and `*WriteServiceInterface` (with `*ServiceInterface` extending both).
- Enforced by: controller dependency tests above and mutation markers (`@mutation` on writes).

## Read-only proxy
- Read interfaces resolve to a runtime proxy that blocks `@mutation` calls.
- Any mutation through a read interface must throw.
- Enforced by: service contract tests and proxy checks (see `tests/Domain/*`).

## DTO purity
- DTOs contain only scalars, arrays, DateTimeImmutable, and other DTOs.
- No DB/Repository/Service/Controller dependencies inside DTOs.
- Enforced by: `DtoPurityTest`.

## Performance budgets
- Budgets are profile-based: `ci` (default) and `strict`.
- ETag/304 has explicit budgets for `/api/v2/pages:304` and `/api/v2/menus:304`.
- Enforced by: `tests/Perf/*` and `policy:check` with `POLICY_PERF=1`.

## Policy gates (opt-in)
- `POLICY_PERF=1` runs perf budgets (profile via `POLICY_PERF_PROFILE=ci|strict`).
- `POLICY_ADMIN_SMOKE=1` runs admin HTML smoke.
- `POLICY_HTTP_SMOKE=1` runs asset HTTP smoke.
- `POLICY_FULL_TESTS=1` runs the full PHPUnit suite.
- Enforced by: `php tools/cli.php policy:check`.
