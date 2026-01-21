# Service Layer Migration Notes (v4.0.20)

## Why the service layer exists
- keep controllers thin and focused on HTTP concerns
- move domain/system logic into testable, reusable classes
- reduce direct repository usage inside controllers
- enable consistent DI container wiring

## Why interfaces are mandatory
- controllers depend on `*ServiceInterface` only (no concrete service types)
- Kernel binds services against interfaces for stable contracts and test isolation
- contract tests fail if a service is missing its interface
- mutating service methods must include `@mutation` (tests enforce common mutation prefixes)
- Read/Write split: services with reads + mutations expose `*ReadServiceInterface` and `*WriteServiceInterface`; GET/HEAD-only controllers depend on Read interfaces only

## Controller boundary (mandatory)
- no repositories, no DatabaseManager, no SQL literals in controllers
- controllers may only call service interfaces (and HTTP/view helpers)
- contract tests enforce this boundary

Before (forbidden):
```php
// controller
$repo = new PagesRepository($db);
$rows = $repo->search($query, 20, 0, 'published');
```

After (required):
```php
// controller
$rows = $pagesService->list([
    'query' => $query,
    'status' => 'published',
    'limit' => 20,
    'offset' => 0,
]);
```

Read-only controller example:
```php
// controller
final class PagesController
{
    public function __construct(private PagesReadServiceInterface $pages) {}
}
```

## Stable output shapes (DTOs)
- For public/headless responses, services return DTOs or validated array shapes.
- Controllers map DTOs to view/JSON; no raw repository rows in responses.

Before (controller reads arrays):
```php
// controller
$rows = $pagesService->list(['status' => 'published']);
foreach ($rows as $row) {
    $titles[] = $row['title'] ?? '';
}
```

After (controller reads DTOs):
```php
// controller
$pages = $pagesService->listPublishedSummaries();
foreach ($pages as $page) {
    $titles[] = $page->title();
}
```

## How to migrate an existing controller
1) Identify the domain/system logic in the controller.
2) Move that logic into a `*Service` class in `src/Domain/*`.
3) Keep the controller as a mapper:
   - Request -> service call
   - service result -> view or JSON
4) Register the service as a singleton in the container.
5) Update the controller to resolve the service via the container (or inject it in tests).
6) Add/adjust tests:
   - service test for core behavior
   - controller test that asserts service usage

## Enforced rules
- Controllers must not touch repositories or DatabaseManager directly.
- Controllers must not instantiate services via `new`.
- GET-only controllers must not depend on `*WriteServiceInterface`.

## Before / After (short)

Before (controller has logic):
```php
// controller
$repo = new PagesRepository($db);
$rows = $repo->search($query, 20, 0, 'published');
```

After (controller delegates to service):
```php
// controller
$rows = $pagesService->list([
    'query' => $query,
    'status' => 'published',
    'limit' => 20,
    'offset' => 0,
]);
```

Users example (roles + status):
```php
// controller
$users = $usersService->list([
    'query' => $query,
    'limit' => 50,
    'offset' => 0,
]);
$roles = $usersService->rolesForUsers($userIds);
```

Menus example (items + tree):
```php
// controller
$menu = $menusService->findByName('main');
$items = $menusService->loadItems((int) ($menu['id'] ?? 0));
$items = $menusService->buildTree($items);
```

Settings example (read + save):
```php
// controller
$payload = $settingsService->settingsWithSources();
$settings = $payload['settings'];

$settingsService->setMany([
    'site_name' => $siteName,
    'default_locale' => $defaultLocale,
    'theme' => $theme,
    'api_token_issue_mode' => $mode,
]);
```

Ops example (snapshot + view data):
```php
// controller
$snapshot = $opsService->overview($request->isHttps());
$viewData = $opsService->viewData($snapshot, fn (string $key) => $view->translate($key));
```

API tokens example (list + create):
```php
// controller
$tokens = $apiTokensService->listTokens($userId);
$created = $apiTokensService->createToken($userId, $name, $scopes, $expiresAt);
```

Admin search example (grouped results):
```php
// controller
$results = $adminSearchService->search($query, $options);
```

## Anti-patterns (do not do this)
- `new SomeService()` inside controllers
- business rules implemented in controllers
- services that return HTML or Response objects
- services that know about Request/Session/View
- adding a repository layer on top of existing repositories
