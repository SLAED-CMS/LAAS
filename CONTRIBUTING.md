# Contributing to LAAS CMS

Thank you for your interest in contributing to LAAS CMS!

This guide will help you get started with development, testing, and submitting contributions.

---

## Table of Contents

- [Project Philosophy](#project-philosophy)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Code Standards](#code-standards)
- [Testing](#testing)
- [Commit Messages](#commit-messages)
- [Pull Requests](#pull-requests)
- [Documentation](#documentation)
- [Debugging](#debugging)
- [Useful Commands](#useful-commands)
- [Getting Help](#getting-help)

---

## Project Philosophy

LAAS CMS is built on these core principles:

- **No frameworks** — Pure PHP 8.4+ with strict types
- **Security first** — 2FA/TOTP, password reset, RBAC, CSRF, rate limiting, session timeout, SSRF protection
- **Frontend-agnostic** — RenderAdapter v1 (HTML/JSON), content negotiation, headless mode
- **Frontend/backend separation** — AssetManager, UI Tokens (no *_class from PHP), ViewModels
- **Predictability** — No magic, clear behavior, honest limitations
- **Production focus** — Ops-friendly, observable, maintainable
- **HTML-first** — No build step, progressive enhancement with HTMX
- **Simplicity** — Minimal abstractions, straightforward architecture
- **Policy enforcement** — CI guardrails (policy checks) for code quality
- **AI Safety** — Human-in-the-loop: All AI proposals must be reviewed and applied via CLI (`--yes`)

Please keep these principles in mind when contributing.

---

## Getting Started

### Requirements

**Minimum:**
- **PHP:** 8.4+
- **Database:** MySQL 8.0+ or MariaDB 10+
- **Composer:** 2.x
- **Git:** 2.x

**Optional:**
- **ClamAV:** For antivirus scanning (media uploads)
- **S3/MinIO:** For cloud storage

### Local Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/SLAED-CMS/LAAS.git
   cd LAAS
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

4. **Configure database:**
   Edit `config/database.php` or use `.env` variables:
   ```env
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=laas
   DB_USER=root
   DB_PASSWORD=
   ```

5. **Run migrations:**
   ```bash
   php tools/cli.php migrate:up
   ```

6. **Sync modules:**
   ```bash
   php tools/cli.php module:sync
   ```

7. **Set up commit template:**
   ```bash
   git config commit.template .gitmessage
   ```

8. **Verify setup:**
   ```bash
   # Check database connection
   php tools/cli.php db:check

   # Run tests
   vendor/bin/phpunit

   # Check module status
   php tools/cli.php module:status
   ```

9. **Start development server:**
   ```bash
   # Built-in PHP server (for quick testing)
   php -S localhost:8000 -t public/

   # Or configure Apache/Nginx to point to public/
   ```

10. **Access the application:**
    - Frontend: `http://localhost:8000/`
    - Admin: `http://localhost:8000/admin`
    - Health: `http://localhost:8000/health`

---

## Development Workflow

### Branch Strategy

- **main** — Stable release branch (production-ready)
- **feature/*** — New features
- **fix/*** — Bug fixes
- **docs/*** — Documentation updates
- **refactor/*** — Code refactoring

### Workflow Steps

1. **Create a feature branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make changes:**
   - Follow [Code Standards](#code-standards)
   - Write tests for new functionality
   - Update documentation as needed

3. **Test your changes:**
   ```bash
   # Run all tests
   vendor/bin/phpunit

   # Run specific test
   vendor/bin/phpunit tests/YourTest.php

   # Run contract tests
   vendor/bin/phpunit --testsuite contracts
   ```

4. **Commit your changes:**
   - Use the commit template (see [Commit Messages](#commit-messages))
   ```bash
   git add .
   git commit
   # Fill in the template
   ```

5. **Push to your fork:**
   ```bash
   git push origin feature/your-feature-name
   ```

6. **Create a Pull Request:**
   - See [Pull Requests](#pull-requests) section

---

## Code Standards

### PHP Standards

- **PHP Version:** 8.4+ strict types
- **PSR-12** coding style (loosely followed)
- **Strict types:** Always use `declare(strict_types=1);`
- **Type hints:** Always declare parameter and return types
- **No mixed types:** Avoid `mixed`, use specific types

### Coding Style

**File Structure:**
```php
<?php
declare(strict_types=1);

namespace Laas\YourNamespace;

use Laas\Core\Something;
use Another\Dependency;

class YourClass
{
    public function __construct(
        private readonly Dependency $dep
    ) {}

    public function yourMethod(string $param): array
    {
        // Implementation
    }
}
```

**Naming Conventions:**
- **Classes:** PascalCase (`UserController`)
- **Methods:** camelCase (`getUserById`)
- **Properties:** camelCase (`$userId`)
- **Constants:** UPPER_SNAKE_CASE (`MAX_UPLOAD_SIZE`)
- **Database tables:** snake_case (`user_roles`)

**Best Practices:**
- Use readonly properties when possible
- Avoid static methods (except for pure utilities)
- Dependency injection over global state
- Explicit is better than implicit
- Fail fast with exceptions in constructors

### Security Requirements

**Input Validation:**
```php
// Always validate user input
$validator = new Validator([
    'email' => 'required|email|max:255',
    'username' => 'required|min:3|max:50',
]);

if (!$validator->validate($data)) {
    // Handle validation errors
}
```

**SQL Queries:**
```php
// Always use prepared statements
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
```

**Output Escaping:**
```php
// In templates, auto-escape by default
{{ user.name }}  // Auto-escaped

// Use raw() only when absolutely necessary
{{ user.bio|raw }}  // Not escaped - dangerous!
```

**CSRF Protection:**
```html
<!-- All forms must include CSRF token -->
<form method="POST">
    <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
    <!-- form fields -->
</form>
```

**2FA/TOTP (v2.4.0):**
```php
// Generate TOTP secret for enrollment
$secret = $totpService->generateSecret();

// Verify TOTP code with grace period
if (!$totpService->verifyCode($user->totp_secret, $code)) {
    throw new AuthException('Invalid 2FA code');
}

// Hash backup codes before storage
$hashedCodes = array_map(
    fn($code) => password_hash($code, PASSWORD_DEFAULT),
    $backupCodes
);
```

**Password Reset (v2.4.0):**
```php
// Generate secure token (32 bytes = 64 hex chars)
$token = bin2hex(random_bytes(32));

// Store with expiry (1 hour)
$resetRepo->createToken($userId, $token, time() + 3600);

// Rate limit: 3 requests per 15 minutes per email
$rateLimiter->check($email, 3, 900);

// Single-use tokens (delete after successful reset)
$resetRepo->deleteToken($token);
```

### File Organization

```
modules/YourModule/
├── Controller/
│   ├── YourController.php
│   └── AdminYourController.php
├── Repository/
│   └── YourRepository.php
├── Service/
│   └── YourService.php
├── lang/
│   ├── en.php
│   └── ru.php
├── routes.php
└── module.json
```

### Documentation Comments

```php
/**
 * Brief description of the method.
 *
 * Longer description if needed.
 * Can span multiple lines.
 *
 * @param string $userId The user ID
 * @param array<string, mixed> $options Optional parameters
 * @return array{id: int, name: string} User data
 * @throws NotFoundException When user not found
 */
public function getUser(string $userId, array $options = []): array
{
    // Implementation
}
```

---

## Testing

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite contracts

# Run policy checks (CI guardrails)
php tools/policy-check.php
# or via CLI
php tools/cli.php policy:check

# Run with coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/

# Run specific test file
vendor/bin/phpunit tests/Unit/YourTest.php

# Before commit (recommended)
php tools/cli.php policy:check && vendor/bin/phpunit
```

### Writing Tests

**Unit Test Example:**
```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laas\YourModule\YourClass;

class YourClassTest extends TestCase
{
    public function testYourMethod(): void
    {
        $instance = new YourClass();
        $result = $instance->yourMethod('input');

        $this->assertSame('expected', $result);
    }
}
```

**Contract Test Example:**
```php
<?php
declare(strict_types=1);

namespace Tests\Contracts;

use PHPUnit\Framework\TestCase;

class ModuleDiscoveryContractTest extends TestCase
{
    public function testAllModulesHaveModuleJson(): void
    {
        $modulesDir = __DIR__ . '/../../modules';
        $modules = glob($modulesDir . '/*', GLOB_ONLYDIR);

        foreach ($modules as $module) {
            $moduleJson = $module . '/module.json';
            $this->assertFileExists(
                $moduleJson,
                "Module " . basename($module) . " must have module.json"
            );
        }
    }
}
```

### Test Coverage

- Aim for **80%+ coverage** for new code
- **100% coverage** for critical security code (auth, RBAC, validation)
- Contract tests protect architectural invariants

---

## Commit Messages

### Format

Use the commit template in `.gitmessage`:

```
type(scope): brief description (max 50 chars)

## What
- Detailed description of changes
- Use bullet points for clarity
- Be specific about what changed

## Why
- Explain the motivation for the change
- Reference issue numbers if applicable (#123)
- Describe the problem being solved

## Test
- How was this tested?
- What scenarios were covered?
- Any edge cases handled?
```

### Commit Types

- **feat** — New feature
- **fix** — Bug fix
- **docs** — Documentation changes
- **refactor** — Code refactoring (no behavior change)
- **test** — Adding or updating tests
- **perf** — Performance improvement
- **chore** — Build/tooling changes
- **security** — Security-related changes

### Scope Examples

- **core** — Core framework changes
- **auth** — Authentication/authorization
- **2fa** — Two-factor authentication (v2.4.0)
- **password-reset** — Password reset flow (v2.4.0)
- **api** — API endpoints and tokens
- **media** — Media module
- **pages** — Pages module
- **changelog** — Changelog module
- **rbac** — RBAC system
- **admin** — Admin UI
- **i18n** — Internationalization
- **docs** — Documentation

### Examples

**Good commit messages:**
```
feat(media): add thumbnail generation for images

## What
- Add MediaTransformService for image resizing
- Generate sm/md/lg thumbnails on upload
- Add /media/thumb/{hash}/{size}.{ext} route

## Why
- Users need thumbnails for faster page loads
- Reduces bandwidth for image-heavy pages
- Refs #142

## Test
- Unit tests for MediaTransformService
- Manual upload of various image formats
- Verified thumbnail sizes: 150x150, 300x300, 600x600
```

```
fix(auth): prevent session fixation on login

## What
- Call session_regenerate_id() after successful login
- Add session regeneration to AuthService::login()

## Why
- Security: prevents session fixation attacks
- OWASP Top 10 compliance

## Test
- Verified session ID changes after login
- Tested with existing sessions
- No impact on existing logged-in users
```

**Bad commit messages:**
```
fix stuff
update files
WIP
small changes
```

---

## Pull Requests

### Before Submitting

1. **Ensure tests pass:**
   ```bash
   vendor/bin/phpunit
   ```

2. **Check code quality:**
   ```bash
   # Run ops check (smoke tests)
   php tools/cli.php ops:check
   ```

3. **Update documentation:**
   - Update relevant docs in `docs/`
   - Add/update comments in code
   - Update CHANGELOG if significant change

4. **Review your own changes:**
   ```bash
   git diff main...feature/your-branch
   ```

### PR Template

```markdown
## Description
Brief description of changes.

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Changes
- List specific changes made
- Be detailed but concise

## Testing
- [ ] Unit tests added/updated
- [ ] Contract tests pass
- [ ] Manual testing completed
- [ ] Tested on: PHP 8.4, MySQL 8.0

## Checklist
- [ ] Code follows project style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex logic
- [ ] Documentation updated
- [ ] No new warnings or errors
- [ ] Tests pass locally
- [ ] Commit messages follow template

## Screenshots (if UI change)
[Add screenshots here]

## Related Issues
Closes #123
Refs #456
```

### PR Review Process

1. **Automated checks** run (GitHub Actions)
2. **Code review** by maintainers
3. **Feedback** addressed in new commits
4. **Approval** by at least one maintainer
5. **Merge** to main (squash or merge commit)

### PR Best Practices

- **Keep PRs small** — Easier to review (< 400 lines changed)
- **One feature per PR** — Don't mix unrelated changes
- **Write descriptive titles** — Clear and concise
- **Reference issues** — Link to related issues
- **Respond to feedback** — Be open to suggestions
- **Update your branch** — Rebase on main regularly

---

## Documentation

### Documentation Files

When making changes, update relevant documentation:

- **[README.md](README.md)** — Project overview, quick start
- **[UPGRADING.md](UPGRADING.md)** — Upgrade guide between versions
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — System design
- **[docs/MODULES.md](docs/MODULES.md)** — Module system
- **[docs/SECURITY.md](docs/SECURITY.md)** — Security features
- **[docs/RBAC.md](docs/RBAC.md)** — Access control
- **[docs/MEDIA.md](docs/MEDIA.md)** — Media system
- **Module-specific docs** — In `modules/YourModule/`

### Documentation Style

- Use **Markdown** format
- Keep lines under **100 characters**
- Use **code blocks** with language hints
- Add **examples** for clarity
- Link to **related documentation**

### Inline Documentation

```php
/**
 * Process uploaded media file.
 *
 * Validates file type, scans for viruses (if enabled),
 * generates SHA-256 hash for deduplication, and stores
 * the file in configured storage disk.
 *
 * @param array{name: string, tmp_name: string, size: int, type: string} $file Uploaded file data
 * @param int $userId User ID performing the upload
 * @return array{id: int, hash: string, path: string} Stored media info
 * @throws MediaException When file is invalid or upload fails
 */
public function processUpload(array $file, int $userId): array
```

---

## Debugging

### Debug Mode

Enable debug mode in `.env`:
```env
APP_DEBUG=true
APP_ENV=development
```

**Warning:** Never enable debug mode in production!

### DevTools

When debug mode is enabled:
1. DevTools panel appears at bottom of page
2. Shows request details, DB queries, performance metrics
3. Requires `debug.view` permission

Grant debug permission:
```bash
php tools/cli.php rbac:grant youruser debug.view
```

### Logging

**View logs:**
```bash
# Application logs
tail -f storage/logs/app-$(date +%Y-%m-%d).log

# Error logs
tail -f storage/logs/error-$(date +%Y-%m-%d).log
```

**Add logging in code:**
```php
use Laas\Core\Log;

Log::info('User logged in', ['user_id' => $userId]);
Log::error('Upload failed', ['file' => $filename, 'error' => $e->getMessage()]);
```

### Common Issues

**Database connection fails:**
```bash
# Check database config
php tools/cli.php db:check

# Verify credentials in .env or config/database.php
```

**Migrations fail:**
```bash
# Check migration status
php tools/cli.php migrate:status

# View detailed error in logs
tail storage/logs/app-$(date +%Y-%m-%d).log
```

**Cache issues:**
```bash
# Clear all caches
php tools/cli.php cache:clear
php tools/cli.php templates:clear

# Warmup cache
php tools/cli.php templates:warmup
```

**Permission issues:**
```bash
# Check RBAC status
php tools/cli.php rbac:status

# Grant permissions
php tools/cli.php rbac:grant username permission.name
```

---

## Useful Commands

### CLI Tools

```bash
# Database
php tools/cli.php db:check                    # Check database connection
php tools/cli.php migrate:status              # Show migration status
php tools/cli.php migrate:up                  # Run migrations

# Cache
php tools/cli.php cache:clear                 # Clear all cache
php tools/cli.php cache:status                # Show cache status
php tools/cli.php cache:prune                 # Prune expired cache entries
php tools/cli.php templates:clear             # Clear template cache
php tools/cli.php templates:warmup            # Warmup templates

# Modules
php tools/cli.php module:status               # List all modules
php tools/cli.php module:sync                 # Sync to database
php tools/cli.php module:enable ModuleName    # Enable module
php tools/cli.php module:disable ModuleName   # Disable module

# Media (v3.9.0+)
php tools/cli.php media:gc                    # Garbage collect orphaned media
php tools/cli.php media:verify                # Verify media integrity

# RBAC
php tools/cli.php rbac:status                 # Show RBAC status
php tools/cli.php rbac:grant user perm        # Grant permission
php tools/cli.php rbac:revoke user perm       # Revoke permission

# Settings
php tools/cli.php settings:get key            # Get setting value
php tools/cli.php settings:set key value      # Set setting value

# Operations
php tools/cli.php ops:check                   # Run smoke tests
php tools/cli.php config:export               # Export config snapshot
php tools/cli.php doctor                      # System diagnostics
php tools/cli.php preflight                   # Pre-deployment checks
php tools/cli.php session:smoke               # Session smoke test (Redis)

# Backup (v3.6.0+)
php tools/cli.php backup:create               # Create database backup
php tools/cli.php backup:list                 # List backups
php tools/cli.php backup:verify file.tar.gz   # Verify backup integrity
php tools/cli.php backup:restore file.tar.gz  # Restore backup
php tools/cli.php backup:prune                # Prune old backups

# CI / Release
php tools/cli.php policy:check                # Run policy checks
php tools/cli.php contracts:check             # Check contracts
php tools/cli.php contracts:fixtures:check    # Check contract fixtures
php tools/cli.php release:check               # Pre-release validation

# AI (v4.0.0+)
php tools/cli.php ai:doctor                   # AI subsystem diagnostics
php tools/cli.php ai:proposal:apply <id> --yes  # Apply saved proposal
php tools/cli.php ai:plan:run <plan> --yes    # Run plan workflow
php tools/cli.php templates:raw:scan          # List raw usage in themes
php tools/cli.php templates:raw:check         # Allowlist baseline check
```

### Development Helpers

```bash
# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage/

# Watch for file changes (requires entr)
find . -name "*.php" | entr -c vendor/bin/phpunit

# Start built-in server
php -S localhost:8000 -t public/

# Check PHP syntax
find . -name "*.php" -exec php -l {} \;
```

---

## Getting Help

### Resources

- **Documentation:** [docs/](docs/)
- **Issues:** [GitHub Issues](https://github.com/SLAED-CMS/LAAS/issues)
- **Discussions:** [GitHub Discussions](https://github.com/SLAED-CMS/LAAS/discussions)
- **Security:** See [SECURITY.md](SECURITY.md)

### Reporting Bugs

When reporting bugs, include:
1. **Description** — What happened?
2. **Expected behavior** — What should happen?
3. **Steps to reproduce** — How to trigger the bug?
4. **Environment** — PHP version, OS, database
5. **Logs** — Relevant error messages
6. **Screenshots** — If UI-related

### Feature Requests

When requesting features:
1. **Use case** — Why do you need this?
2. **Proposed solution** — How should it work?
3. **Alternatives** — What else did you consider?
4. **Examples** — Similar features in other projects

### Questions

For questions:
1. Check [documentation](docs/)
2. Search [existing issues](https://github.com/SLAED-CMS/LAAS/issues)
3. Open a [discussion](https://github.com/SLAED-CMS/LAAS/discussions)

---

## Code of Conduct

- Be respectful and professional
- Provide constructive feedback
- Focus on the code, not the person
- Help newcomers learn
- Follow project conventions

---

## License

By contributing to LAAS CMS, you agree that your contributions will be licensed under the MIT License.

---

**Thank you for contributing to LAAS CMS!**

For more information, see:
- [Architecture Overview](docs/ARCHITECTURE.md)
- [Coding Standards](docs/CODING_STANDARDS.md)
- [Security Guide](docs/SECURITY.md)
- [Production Guide](docs/PRODUCTION.md)

**Last updated:** January 2026
