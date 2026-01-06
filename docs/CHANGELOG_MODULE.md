# Changelog Module

**Current version:** v2.3.10

## Overview
- Provides a read-only changelog feed for commits
- Two sources: GitHub API or local git repository
- Frontend page: `/changelog`
- Admin settings: `/admin/changelog`

## Sources
### GitHub
- Uses GitHub REST API commits endpoint
- Token is server-side only
- Token mode: env (recommended)
- Rate limits apply

### Local git
- Reads commits via `git log` on the server
- Repository path must be under the project root
- Requires `git` binary on the server
- Configurable git binary path (v2.3.3+)
  - Default: `git` (uses system PATH)
  - Custom path: e.g., `C:\Program Files\Git\cmd\git.exe`
  - Useful for Windows environments where git is not in PATH

## Security
- No tokens or secrets in frontend output
- Admin errors are sanitized
- Repository path is validated and masked in UI
- No web endpoint executes arbitrary git commands

## Caching
- File-based cache in `storage/cache/changelog`
- Key namespace: `changelog:v1:<source>:<branch>:<page>:<perPage>:<merges>`
- TTL configurable (30–3600 seconds)
- Admin action to clear module cache

## Admin actions
- Save settings
- Test source connection
- Preview commits
- Clear cache (requires `changelog.cache.clear`)

## Permissions
- `changelog.view` (optional for frontend)
- `changelog.admin` (admin UI)
- `changelog.cache.clear` (cache clear)

## Version History

### v2.3.3 - Settings Persistence Fix
- **Fixed:** Race condition in settings cache during parallel requests
- **Fixed:** Settings not persisting when saved via admin UI
- **Added:** Atomic save pattern (setWithoutInvalidation + invalidateSettings)
- **Added:** Configurable git binary path for Local Git provider
- **Added:** Enhanced error logging for git execution diagnostics

### v2.3.2 - Initial Release
- Frontend changelog feed with pagination
- Admin settings, source test, preview, cache clear
- GitHub API and local git providers with cache

**Last updated:** January 2026
