# Contract Tests

**Contract tests** protect stable v2.x behaviors across modules and core components. They act as architectural guardrails to prevent regressions and ensure system invariants are maintained.

---

## Table of Contents

1. [Overview](#overview)
2. [Philosophy](#philosophy)
3. [Contract Types](#contract-types)
4. [Running Contract Tests](#running-contract-tests)
5. [Module Contract Tests](#module-contract-tests)
6. [Storage Contract Tests](#storage-contract-tests)
7. [Media Contract Tests](#media-contract-tests)
8. [Writing New Contract Tests](#writing-new-contract-tests)
9. [Contract vs Unit Tests](#contract-vs-unit-tests)
10. [CI/CD Integration](#cicd-integration)
11. [Troubleshooting](#troubleshooting)
12. [Best Practices](#best-practices)

---

## Overview

Contract tests validate **architectural invariants** — behaviors that MUST NOT change across versions.

**Purpose:**
- Prevent breaking changes to stable APIs
- Ensure module structure remains consistent
- Validate critical security behaviors (e.g., media security headers)
- Document expected system behavior

**Introduced in:** v2.2.5

**Location:** `tests/Contracts/`

---

## Philosophy

### What Are Contract Tests?

Contract tests are **higher-level** than unit tests but **narrower** than integration tests.

**They validate:**
- **Structural contracts:** "All modules must have a `module.json` file"
- **Behavioral contracts:** "Missing media files return HTTP 404"
- **Security contracts:** "Media serving includes `X-Content-Type-Options: nosniff`"

**They do NOT test:**
- Implementation details (that's unit tests)
- End-to-end user flows (that's integration/E2E tests)
- Performance or load characteristics

### Why Contract Tests?

**Problem:**
- As LAAS CMS matures, **stability guarantees** are critical
- Refactoring should be safe — you can change HOW something works, but not WHAT it does
- Module structure must remain consistent for predictability

**Solution:**
- Contract tests enforce invariants
- Breaking a contract = breaking change (requires major version bump)
- Contracts make refactoring safe and confident

---

## Contract Types

### 1. Module Contracts

**File:** `tests/Contracts/ModuleContractTestCase.php`

**Validates:**
- Every module has a `module.json` file
- `module.json` includes required fields: `name`, `type`, `version`, `description`
- Module class exists: `{ModuleName}Module.php`
- `routes.php` exists
- If `lang/` directory exists, `lang/en.php` must exist

**Why:**
- Ensures all modules follow the same structure
- Prevents "orphaned" or malformed modules
- Makes module discovery predictable

### 2. Storage Contracts

**File:** `tests/Contracts/StorageContractTest.php`

**Validates:**
- `put(file, contents)` stores a file
- `getStream(file)` returns a stream resource
- `exists(file)` returns boolean
- `delete(file)` removes a file
- **Missing files return safe defaults** (no crashes)

**Why:**
- Storage drivers (local, S3) must be interchangeable
- Code should not crash on missing files
- Ensures consistent error handling

### 3. Media Contracts

**File:** `tests/Contracts/MediaContractTest.php`

**Validates:**
- **Missing media returns HTTP 404** (not 500, not silent failure)
- **Successful media serving includes `X-Content-Type-Options: nosniff`** (security)
- **Signed URLs are enforced** when enabled (security)

**Why:**
- Media security is critical (prevents MIME sniffing attacks)
- Missing media should not crash the application
- Signed URL enforcement prevents unauthorized access

---

## Running Contract Tests

### Run All Contract Tests

```bash
vendor/bin/phpunit --testsuite default
```

**Or explicitly:**
```bash
vendor/bin/phpunit tests/Contracts/
```

### Run Specific Contract Test

```bash
# Module contracts
vendor/bin/phpunit tests/Contracts/ModuleContractTestCase.php

# Storage contracts
vendor/bin/phpunit tests/Contracts/StorageContractTest.php

# Media contracts
vendor/bin/phpunit tests/Contracts/MediaContractTest.php
```

### Expected Output

```
PHPUnit 10.5.x by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.x
Configuration: phpunit.xml

...........                                                        11 / 11 (100%)

Time: 00:01.234, Memory: 12.00 MB

OK (11 tests, 32 assertions)
```

---

## Module Contract Tests

### What It Validates

**Every module in `modules/` must:**
1. Have a `module.json` file
2. `module.json` must include:
   - `name` (string)
   - `type` (string)
   - `version` (string)
   - `description` (string)
3. Have a module class: `{ModuleName}Module.php`
4. Have a `routes.php` file
5. If `lang/` directory exists, `lang/en.php` must exist

### How It Works

**Discovery:**
```php
// Automatically discovers all modules by finding module.json files
$moduleDirs = glob('modules/*/module.json');
```

**Per-module checks:**
```php
foreach ($moduleDirs as $moduleJsonPath) {
    $moduleDir = dirname($moduleJsonPath);
    $moduleJson = json_decode(file_get_contents($moduleJsonPath), true);

    // Validate required fields
    $this->assertArrayHasKey('name', $moduleJson);
    $this->assertArrayHasKey('type', $moduleJson);
    $this->assertArrayHasKey('version', $moduleJson);
    $this->assertArrayHasKey('description', $moduleJson);

    // Validate module class exists
    $moduleName = basename($moduleDir);
    $moduleClass = "Modules\\{$moduleName}\\{$moduleName}Module";
    $this->assertTrue(class_exists($moduleClass));

    // Validate routes.php exists
    $this->assertFileExists("{$moduleDir}/routes.php");

    // If lang/ exists, en.php must exist
    if (is_dir("{$moduleDir}/lang")) {
        $this->assertFileExists("{$moduleDir}/lang/en.php");
    }
}
```

### Example Failure

**Scenario:** Module `Blog` is missing `lang/en.php` but has a `lang/` directory.

**Output:**
```
Failed asserting that file "modules/Blog/lang/en.php" exists.

tests/Contracts/ModuleContractTestCase.php:45
```

**Fix:**
```bash
# Create the missing file
touch modules/Blog/lang/en.php
```

---

## Storage Contract Tests

### What It Validates

**Storage drivers must:**
1. **Store files** with `put(path, contents)`
2. **Retrieve files** with `getStream(path)` (returns stream resource)
3. **Check existence** with `exists(path)` (returns boolean)
4. **Delete files** with `delete(path)`
5. **Handle missing files gracefully** (no exceptions, safe defaults)

### How It Works

**Test fixture:**
```php
public function testPutAndGetStream(): void
{
    $storage = new LocalStorageDriver('storage/uploads');
    $testFile = 'test_' . uniqid() . '.txt';
    $content = 'Hello, World!';

    // Put file
    $storage->put($testFile, $content);

    // Get stream
    $stream = $storage->getStream($testFile);
    $this->assertIsResource($stream);

    // Read content
    $retrieved = stream_get_contents($stream);
    $this->assertEquals($content, $retrieved);

    // Cleanup
    $storage->delete($testFile);
}
```

**Missing file safety:**
```php
public function testMissingFileDoesNotCrash(): void
{
    $storage = new LocalStorageDriver('storage/uploads');

    // Should return false, not throw exception
    $exists = $storage->exists('non-existent-file.txt');
    $this->assertFalse($exists);

    // Should return null or throw specific exception, not crash
    $stream = $storage->getStream('non-existent-file.txt');
    $this->assertNull($stream);
}
```

### Example Failure

**Scenario:** `LocalStorageDriver::exists()` throws an exception instead of returning `false`.

**Output:**
```
UnexpectedValueException: File does not exist

tests/Contracts/StorageContractTest.php:32
```

**Fix:**
```php
// Before (WRONG)
public function exists(string $path): bool
{
    if (!file_exists($this->basePath . '/' . $path)) {
        throw new UnexpectedValueException('File does not exist');
    }
    return true;
}

// After (CORRECT)
public function exists(string $path): bool
{
    return file_exists($this->basePath . '/' . $path);
}
```

---

## Media Contract Tests

### What It Validates

**Media serving must:**
1. **Return HTTP 404 for missing media** (not 500, not empty response)
2. **Include `X-Content-Type-Options: nosniff`** on successful serves (security)
3. **Enforce signed URLs** when enabled (security)

### How It Works

**404 for missing media:**
```php
public function testMissingMediaReturns404(): void
{
    $request = new Request('/media/nonexistent-hash.jpg');
    $response = $this->app->handle($request);

    $this->assertEquals(404, $response->getStatusCode());
}
```

**Security header check:**
```php
public function testMediaIncludesNosniffHeader(): void
{
    // Upload a real test file
    $media = $this->uploadTestFile('test.jpg');

    // Request the media
    $request = new Request("/media/{$media->hash}.jpg");
    $response = $this->app->handle($request);

    // Validate response
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('nosniff', $response->getHeader('X-Content-Type-Options'));
}
```

**Signed URL enforcement:**
```php
public function testSignedUrlEnforced(): void
{
    // Enable signed URLs in test environment
    config()->set('media.signed_urls_enabled', true);

    // Upload a private file
    $media = $this->uploadTestFile('private.pdf');

    // Direct access should fail
    $request = new Request("/media/{$media->hash}.pdf");
    $response = $this->app->handle($request);
    $this->assertEquals(403, $response->getStatusCode());

    // Signed URL should succeed
    $signedUrl = MediaSignedUrlService::generate($media->hash, 3600);
    $request = new Request($signedUrl);
    $response = $this->app->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
}
```

### Example Failure

**Scenario:** Media serving is missing `X-Content-Type-Options: nosniff` header.

**Output:**
```
Failed asserting that header 'X-Content-Type-Options' is set.

tests/Contracts/MediaContractTest.php:28
```

**Fix:**
```php
// MediaServeController.php
public function serve(string $hash, string $ext): Response
{
    // ... file retrieval logic ...

    return new Response($stream, 200, [
        'Content-Type' => $mimeType,
        'X-Content-Type-Options' => 'nosniff', // ADD THIS
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
}
```

---

## Writing New Contract Tests

### When to Write a Contract Test

**Write a contract test when:**
- You have a **structural requirement** (e.g., "all modules must have X")
- You have a **critical behavioral invariant** (e.g., "404 for missing resources")
- You have a **security requirement** (e.g., "security headers must be present")

**Don't write a contract test for:**
- Implementation details (use unit tests)
- Business logic (use unit/integration tests)
- UI behavior (use E2E tests)

### Contract Test Template

```php
<?php
declare(strict_types=1);

namespace Tests\Contracts;

use PHPUnit\Framework\TestCase;

class YourContractTest extends TestCase
{
    /**
     * @test
     */
    public function it_validates_your_invariant(): void
    {
        // Arrange: Set up test environment
        $subject = new YourSubject();

        // Act: Perform the action
        $result = $subject->doSomething();

        // Assert: Validate the contract
        $this->assertTrue($result->meetsContract());
    }
}
```

### Example: API Contract Test

**Scenario:** All API endpoints must return JSON with `application/json` content type.

```php
<?php
declare(strict_types=1);

namespace Tests\Contracts;

use PHPUnit\Framework\TestCase;

class ApiContractTest extends TestCase
{
    /**
     * @test
     */
    public function all_api_endpoints_return_json_content_type(): void
    {
        $apiEndpoints = [
            '/api/v1/ping',
            '/api/v1/health',
            '/csrf',
        ];

        foreach ($apiEndpoints as $endpoint) {
            $request = new Request($endpoint);
            $response = $this->app->handle($request);

            $this->assertStringContainsString(
                'application/json',
                $response->getHeader('Content-Type'),
                "Endpoint {$endpoint} must return JSON"
            );
        }
    }
}
```

---

## Contract vs Unit Tests

### Contract Tests

**Focus:** Architectural invariants and behaviors that must not change

**Scope:** High-level, cross-cutting concerns

**Examples:**
- "All modules have `module.json`"
- "404 for missing resources"
- "Security headers present"

**Failures:** Indicate breaking change (major version bump required)

### Unit Tests

**Focus:** Specific functionality and edge cases

**Scope:** Single class or function

**Examples:**
- "MediaRepository::findByHash() returns Media object"
- "MediaRepository::findByHash() returns null when not found"
- "MediaRepository::findByHash() throws exception on invalid hash"

**Failures:** Indicate bug or regression in implementation

### When to Use Which

| Scenario | Contract Test | Unit Test |
|----------|---------------|-----------|
| All modules must have `module.json` | ✅ | ❌ |
| Module class constructor accepts dependencies | ❌ | ✅ |
| Missing media returns 404 | ✅ | ❌ |
| MediaRepository::findByHash() returns Media | ❌ | ✅ |
| Security headers present on media serving | ✅ | ❌ |
| Image thumbnails are generated with correct dimensions | ❌ | ✅ |

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Contract Tests

on: [push, pull_request]

jobs:
  contracts:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader

      - name: Run contract tests
        run: vendor/bin/phpunit --testsuite default

      - name: Fail on contract violations
        run: exit $?
```

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit

echo "Running contract tests..."
vendor/bin/phpunit --testsuite default

if [ $? -ne 0 ]; then
    echo "Contract tests failed. Commit aborted."
    exit 1
fi
```

---

## Troubleshooting

### Contract Test Failures

**1. Module contract fails: "module.json not found"**

**Cause:** Module directory missing `module.json`

**Fix:**
```bash
# Create module.json
cat > modules/YourModule/module.json <<EOF
{
  "name": "YourModule",
  "type": "feature",
  "version": "1.0.0",
  "description": "Your module description"
}
EOF
```

**2. Storage contract fails: "Expected false, got exception"**

**Cause:** Storage driver throws exception instead of returning safe default

**Fix:**
```php
// Wrap in try-catch or check before throwing
public function exists(string $path): bool
{
    try {
        return file_exists($this->basePath . '/' . $path);
    } catch (\Exception $e) {
        return false;
    }
}
```

**3. Media contract fails: "Header 'X-Content-Type-Options' not found"**

**Cause:** Missing security header in media serving

**Fix:**
```php
// Add to response headers
return new Response($stream, 200, [
    'Content-Type' => $mimeType,
    'X-Content-Type-Options' => 'nosniff',
    // ... other headers
]);
```

### Skipping Contract Tests (NOT Recommended)

**Emergency only:**
```bash
# Skip contract tests (use with caution)
vendor/bin/phpunit --exclude-group contracts
```

**Why NOT recommended:**
- Contract violations indicate breaking changes
- Skipping tests hides problems
- Production may break unexpectedly

**Better approach:**
- Fix the contract violation
- If the contract is wrong, update it (requires major version bump)

---

## Best Practices

### 1. Keep Contracts Stable

**Contracts should RARELY change.** A contract change is a **breaking change** and requires a major version bump (v2 → v3).

**Good:**
- Add new optional behaviors
- Relax constraints (e.g., allow more file types)

**Bad:**
- Remove required fields from `module.json`
- Change expected HTTP status codes
- Remove security headers

### 2. Test Real Behavior, Not Mocks

**Good:**
```php
// Test real storage driver
$storage = new LocalStorageDriver('storage/uploads');
$storage->put('test.txt', 'content');
$this->assertTrue($storage->exists('test.txt'));
```

**Bad:**
```php
// Don't mock in contract tests
$storage = $this->createMock(StorageInterface::class);
$storage->method('exists')->willReturn(true);
```

**Why:** Contract tests validate real system behavior, not mocks.

### 3. Run Contract Tests in CI/CD

Always run contract tests in CI/CD before merging or deploying.

**GitHub Actions:**
```yaml
- name: Run contract tests
  run: vendor/bin/phpunit --testsuite default
```

### 4. Document Contracts

**Each contract test should have a docblock explaining:**
- What invariant it protects
- Why it matters
- What happens if it fails

**Example:**
```php
/**
 * Ensures all modules have a module.json file with required fields.
 *
 * Why: Module discovery relies on module.json structure.
 * Breaking this contract would break the module system.
 *
 * @test
 */
public function all_modules_have_valid_module_json(): void
{
    // ...
}
```

### 5. Fail Fast, Fail Loud

**Contract violations should:**
- Fail immediately in CI/CD
- Block merges/deployments
- Trigger alerts

**Don't:**
- Ignore contract failures
- Skip contract tests in production pipelines
- "Fix later" — fix NOW or revert the change

---

**Last updated:** January 2026
