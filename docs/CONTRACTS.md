# Contract Tests

Contract tests protect stable v2.x behaviors across modules and core.

## How To Run
- `vendor/bin/phpunit --testsuite default`
- or run a single test file from `tests/Contracts/`.

## Module Contract Tests
The base test `tests/Contracts/ModuleContractTestCase.php` discovers modules by `module.json`.

Checks:
- `module.json` includes `name`, `type`, `version`, `description`
- module class `<ModuleName>Module.php` exists
- `routes.php` exists
- `lang/en.php` exists if `lang/` directory is present

## Storage Contract Tests
`tests/Contracts/StorageContractTest.php` validates the local storage driver:
- `put`, `getStream`, `delete`, `exists`
- missing files do not crash and return safe defaults

## Media Contract Tests
`tests/Contracts/MediaContractTest.php` validates media serve invariants:
- missing media returns `404`
- `X-Content-Type-Options: nosniff` is present on successful serve
- signed URLs are enforced when enabled in the test environment
