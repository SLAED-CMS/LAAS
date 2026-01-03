# LAAS CMS - Предложения по улучшению

> Дата: Январь 2026
> Текущая версия: v1.10.0
> Статус: Roadmap для дальнейшего развития

---

## Легенда

- **Приоритет**: 🔴 Высокий | 🟡 Средний | 🟢 Низкий
- **Сложность**: ⭐ Простая (1-3 дня) | ⭐⭐ Средняя (1 неделя) | ⭐⭐⭐ Сложная (2-4 недели)
- **Эффект**: 🚀 Критичный | 📈 Значительный | ✨ Улучшение

---

# 1. СКОРОСТЬ (Performance)

## 🔴 P1: Redis Integration для кеширования

**Приоритет**: 🔴 Высокий
**Сложность**: ⭐⭐ Средняя
**Эффект**: 🚀 Критичный (5-10x ускорение sessions и cache)

### Проблема
- File-based sessions медленные при высокой нагрузке
- Rate limiter использует file locks (bottleneck при concurrency)
- Settings загружаются из DB на каждом request
- Template cache через файловую систему

### Решение

**1. Установка зависимости**
```bash
composer require predis/predis
```

**2. Session Handler**
```php
// src/Http/Session/RedisSessionHandler.php
<?php

declare(strict_types=1);

namespace Laas\Http\Session;

use SessionHandlerInterface;
use Predis\Client;

class RedisSessionHandler implements SessionHandlerInterface
{
    private Client $redis;
    private int $ttl = 3600;
    private string $prefix = 'session:';

    public function __construct(Client $redis, int $ttl = 3600)
    {
        $this->redis = $redis;
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->redis->get($this->prefix . $id);
        return $data ?: '';
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->redis->setex(
            $this->prefix . $id,
            $this->ttl,
            $data
        );
    }

    public function destroy(string $id): bool
    {
        return (bool) $this->redis->del([$this->prefix . $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis автоматически удаляет expired keys
        return 0;
    }
}
```

**3. Cache Provider**
```php
// src/Cache/RedisCache.php
<?php

declare(strict_types=1);

namespace Laas\Cache;

use Predis\Client;

class RedisCache implements CacheInterface
{
    private Client $redis;
    private string $prefix = 'cache:';

    public function __construct(Client $redis, string $prefix = 'cache:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->prefix . $key);

        if ($value === null) {
            return $default;
        }

        return unserialize($value);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $serialized = serialize($value);

        if ($ttl > 0) {
            return (bool) $this->redis->setex($this->prefix . $key, $ttl, $serialized);
        }

        return (bool) $this->redis->set($this->prefix . $key, $serialized);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->redis->del([$this->prefix . $key]);
    }

    public function flush(): bool
    {
        // Удалить все ключи с префиксом
        $keys = $this->redis->keys($this->prefix . '*');

        if (empty($keys)) {
            return true;
        }

        return (bool) $this->redis->del($keys);
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }
}
```

**4. Rate Limiter (Redis-based)**
```php
// src/Security/RedisRateLimiter.php
<?php

declare(strict_types=1);

namespace Laas\Security;

use Predis\Client;

class RedisRateLimiter
{
    private Client $redis;
    private string $prefix = 'ratelimit:';

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $fullKey = $this->prefix . $key;
        $attempts = $this->redis->incr($fullKey);

        if ($attempts === 1) {
            $this->redis->expire($fullKey, $decaySeconds);
        }

        return $attempts <= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $attempts = (int) $this->redis->get($this->prefix . $key) ?: 0;
        return max(0, $maxAttempts - $attempts);
    }

    public function reset(string $key): bool
    {
        return (bool) $this->redis->del([$this->prefix . $key]);
    }

    public function clear(): bool
    {
        $keys = $this->redis->keys($this->prefix . '*');

        if (empty($keys)) {
            return true;
        }

        return (bool) $this->redis->del($keys);
    }
}
```

**5. Config**
```php
// config/cache.php
<?php

return [
    'driver' => env('CACHE_DRIVER', 'file'), // file|redis

    'redis' => [
        'scheme' => env('REDIS_SCHEME', 'tcp'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => (int) env('REDIS_DB', 0),
        'prefix' => env('REDIS_PREFIX', 'laas:'),
    ],

    'file' => [
        'path' => __DIR__ . '/../storage/cache',
    ],
];
```

**6. .env**
```ini
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
REDIS_PREFIX=laas:
```

**7. Integration в Kernel**
```php
// src/Core/Kernel.php
private function initializeCache(): CacheInterface
{
    $driver = config('cache.driver');

    if ($driver === 'redis') {
        $redisConfig = config('cache.redis');
        $client = new \Predis\Client([
            'scheme' => $redisConfig['scheme'],
            'host' => $redisConfig['host'],
            'port' => $redisConfig['port'],
            'password' => $redisConfig['password'],
            'database' => $redisConfig['database'],
        ]);

        return new \Laas\Cache\RedisCache($client, $redisConfig['prefix']);
    }

    return new \Laas\Cache\FileCache(config('cache.file.path'));
}
```

### Выгода
- **Sessions**: 10x быстрее read/write операции
- **Rate limiter**: устранение file lock contention
- **Cache**: <1ms доступ vs 5-10ms файлы
- **Horizontal scaling**: shared state между серверами
- **Atomic operations**: INCR для rate limiting без race conditions

---

## 🔴 P2: Database Query Optimization

**Приоритет**: 🔴 Высокий
**Сложность**: ⭐⭐ Средняя
**Эффект**: 🚀 Критичный (50-80% снижение DB load)

### Проблема
- N+1 queries в списках (media list показывает uploaded_by username - отдельный запрос на каждую строку)
- Отсутствуют индексы на часто используемых полях
- Нет query result caching для редко меняющихся данных
- Медленные JOIN запросы без оптимизации

### Решение

**1. Migration: Performance Indexes**
```php
// database/migrations/core/20260115_000000_add_performance_indexes.php
<?php

return new class {
    public function up(\PDO $pdo): void
    {
        // Users table
        $pdo->exec("CREATE INDEX idx_users_username ON users(username)");
        $pdo->exec("CREATE INDEX idx_users_email ON users(email)");

        // Media files
        $pdo->exec("CREATE INDEX idx_media_sha256 ON media_files(sha256)");
        $pdo->exec("CREATE INDEX idx_media_mime ON media_files(mime_type)");
        $pdo->exec("CREATE INDEX idx_media_created ON media_files(created_at)");
        $pdo->exec("CREATE INDEX idx_media_uploaded_by ON media_files(uploaded_by)");

        // Pages
        $pdo->exec("CREATE INDEX idx_pages_slug ON pages(slug)");
        $pdo->exec("CREATE INDEX idx_pages_status ON pages(is_draft)");
        $pdo->exec("CREATE INDEX idx_pages_deleted ON pages(deleted_at)");

        // Audit logs
        $pdo->exec("CREATE INDEX idx_audit_user_action ON audit_logs(user_id, action)");
        $pdo->exec("CREATE INDEX idx_audit_created ON audit_logs(created_at)");

        // RBAC
        $pdo->exec("CREATE INDEX idx_role_user_user ON role_user(user_id)");
        $pdo->exec("CREATE INDEX idx_role_user_role ON role_user(role_id)");
        $pdo->exec("CREATE INDEX idx_perm_role_role ON permission_role(role_id)");
        $pdo->exec("CREATE INDEX idx_perm_role_perm ON permission_role(permission_id)");

        // Menu items
        $pdo->exec("CREATE INDEX idx_menu_items_menu ON menu_items(menu_id, `order`)");

        // Settings
        $pdo->exec("CREATE UNIQUE INDEX uk_settings_key ON settings(`key`)");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP INDEX idx_users_username ON users");
        $pdo->exec("DROP INDEX idx_users_email ON users");
        $pdo->exec("DROP INDEX idx_media_sha256 ON media_files");
        $pdo->exec("DROP INDEX idx_media_mime ON media_files");
        $pdo->exec("DROP INDEX idx_media_created ON media_files");
        $pdo->exec("DROP INDEX idx_media_uploaded_by ON media_files");
        $pdo->exec("DROP INDEX idx_pages_slug ON pages");
        $pdo->exec("DROP INDEX idx_pages_status ON pages");
        $pdo->exec("DROP INDEX idx_pages_deleted ON pages");
        $pdo->exec("DROP INDEX idx_audit_user_action ON audit_logs");
        $pdo->exec("DROP INDEX idx_audit_created ON audit_logs");
        $pdo->exec("DROP INDEX idx_role_user_user ON role_user");
        $pdo->exec("DROP INDEX idx_role_user_role ON role_user");
        $pdo->exec("DROP INDEX idx_perm_role_role ON permission_role");
        $pdo->exec("DROP INDEX idx_perm_role_perm ON permission_role");
        $pdo->exec("DROP INDEX idx_menu_items_menu ON menu_items");
        $pdo->exec("DROP INDEX uk_settings_key ON settings");
    }
};
```

**2. Eager Loading для устранения N+1**
```php
// modules/Media/Repository/MediaRepository.php

// БЫЛО: N+1 queries
public function getAll(int $limit = 50, int $offset = 0): array
{
    $sql = "SELECT * FROM media_files ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $files = $this->db->query($sql, ['limit' => $limit, 'offset' => $offset]);

    // Каждый файл делает отдельный запрос для uploaded_by
    foreach ($files as &$file) {
        $file['uploaded_by_user'] = $this->usersRepo->findById($file['uploaded_by']);
    }

    return $files;
}

// СТАЛО: 1 запрос с JOIN
public function getAll(int $limit = 50, int $offset = 0): array
{
    $sql = "
        SELECT
            m.*,
            u.username as uploaded_by_username,
            u.email as uploaded_by_email
        FROM media_files m
        LEFT JOIN users u ON m.uploaded_by = u.id
        ORDER BY m.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    return $this->db->query($sql, [
        'limit' => $limit,
        'offset' => $offset,
    ]);
}
```

**3. Query Result Cache**
```php
// src/Database/DatabaseManager.php
private $cache;

public function __construct(\PDO $pdo, $cache = null)
{
    $this->pdo = $pdo;
    $this->cache = $cache;
}

public function cachedQuery(string $key, string $sql, array $params = [], int $ttl = 300): array
{
    if ($this->cache === null) {
        return $this->query($sql, $params);
    }

    $cached = $this->cache->get($key);

    if ($cached !== null) {
        return $cached;
    }

    $result = $this->query($sql, $params);
    $this->cache->set($key, $result, $ttl);

    return $result;
}

// Использование в Settings
public function get(string $key, mixed $default = null): mixed
{
    return $this->db->cachedQuery(
        "settings:$key",
        "SELECT value FROM settings WHERE `key` = :key",
        ['key' => $key],
        3600 // 1 hour TTL
    )[0]['value'] ?? $default;
}
```

**4. Prepared Statement Pooling**
```php
// config/database.php
return [
    'driver' => 'mysql',
    'host' => env('DB_HOST', 'localhost'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_NAME', 'laas'),
    'username' => env('DB_USER', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'options' => [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', true), // ← Connection pooling
        \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
];
```

### Выгода
- **Indexes**: 10-100x ускорение поиска по username, email, slug
- **Eager loading**: устранение N+1 queries (50+ запросов → 1 запрос)
- **Query cache**: снижение DB load на 50-70% для частых запросов
- **Connection pool**: меньше overhead на создание connections

---

## 🟡 P3: OpCache Configuration Guide

**Приоритет**: 🟡 Средний
**Сложность**: ⭐ Простая
**Эффект**: 📈 Значительный (2-3x ускорение PHP execution)

### Решение

Добавить в документацию рекомендуемые настройки для production.

**docs/DEPLOYMENT.md**
```markdown
# Production Deployment Guide

## PHP Configuration

### OpCache (Recommended for Production)

OpCache кеширует скомпилированный bytecode PHP, ускоряя выполнение в 2-3 раза.

**php.ini**
```ini
[opcache]
; Enable OpCache
opcache.enable=1
opcache.enable_cli=0

; Memory settings
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000

; Performance settings (production)
opcache.validate_timestamps=0        ; Отключить проверку изменений файлов
opcache.revalidate_freq=0
opcache.save_comments=0
opcache.fast_shutdown=1
opcache.huge_code_pages=1            ; Если доступно в ОС

; File restrictions
opcache.restrict_api=/var/www/laas/tools/opcache-clear.php

; JIT compiler (PHP 8.4+)
opcache.jit_buffer_size=128M
opcache.jit=tracing
```

**Development Settings**
```ini
; Development: проверять изменения файлов
opcache.validate_timestamps=1
opcache.revalidate_freq=2            ; Проверять каждые 2 секунды
```

### Очистка OpCache при деплое

**tools/opcache-clear.php**
```php
<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OpCache cleared successfully\n";
    echo "Status:\n";
    print_r(opcache_get_status(false));
} else {
    echo "OpCache not enabled\n";
}
```

**Деплой скрипт**
```bash
#!/bin/bash
git pull origin main
composer install --no-dev --optimize-autoloader --classmap-authoritative
php tools/cli.php migrate:up
php tools/cli.php cache:clear
php tools/opcache-clear.php           # ← Очистка OpCache
sudo systemctl reload php8.4-fpm
```

### Мониторинг OpCache

**Добавить в DevTools**
```php
// src/DevTools/OpCacheCollector.php
class OpCacheCollector implements CollectorInterface
{
    public function collect(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => false];
        }

        $status = opcache_get_status(false);

        return [
            'enabled' => true,
            'memory_usage' => $status['memory_usage'] ?? [],
            'statistics' => $status['opcache_statistics'] ?? [],
            'hit_rate' => $this->calculateHitRate($status),
        ];
    }

    private function calculateHitRate(array $status): float
    {
        $stats = $status['opcache_statistics'] ?? [];
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? ($hits / $total) * 100 : 0;
    }
}
```
```

---

## 🟡 P4: Asset Pipeline (Minification & Versioning)

**Приоритет**: 🟡 Средний
**Сложность**: ⭐⭐ Средняя
**Эффект**: 📈 Значительный (30-50% faster page load)

### Проблема
- Нет минификации CSS/JS
- Нет версионирования (cache busting)
- Ручное управление зависимостями

### Решение

**1. Vite Setup**
```bash
npm init -y
npm install --save-dev vite @vitejs/plugin-legacy
```

**package.json**
```json
{
  "name": "laas-cms",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview"
  },
  "devDependencies": {
    "vite": "^5.0.0",
    "@vitejs/plugin-legacy": "^5.0.0"
  }
}
```

**vite.config.js**
```js
import { defineConfig } from 'vite';
import legacy from '@vitejs/plugin-legacy';

export default defineConfig({
  build: {
    outDir: 'public/dist',
    manifest: true,
    rollupOptions: {
      input: {
        app: 'resources/js/app.js',
        admin: 'resources/js/admin.js',
        styles: 'resources/css/app.css',
        adminStyles: 'resources/css/admin.css',
      }
    }
  },
  plugins: [
    legacy({
      targets: ['defaults', 'not IE 11']
    })
  ],
  server: {
    proxy: {
      '/api': 'http://laas.loc'
    }
  }
});
```

**2. Asset Helper**
```php
// src/View/AssetHelper.php
<?php

declare(strict_types=1);

namespace Laas\View;

class AssetHelper
{
    private static ?array $manifest = null;

    public static function asset(string $path): string
    {
        if (config('app.env') === 'dev') {
            // Development: Vite dev server
            return "http://localhost:5173/$path";
        }

        // Production: использовать manifest
        if (self::$manifest === null) {
            $manifestPath = __DIR__ . '/../../public/dist/manifest.json';

            if (!file_exists($manifestPath)) {
                return "/dist/$path";
            }

            self::$manifest = json_decode(file_get_contents($manifestPath), true);
        }

        return '/dist/' . (self::$manifest[$path]['file'] ?? $path);
    }

    public static function clearManifest(): void
    {
        self::$manifest = null;
    }
}
```

**3. Template Engine Integration**
```php
// src/View/Template/TemplateCompiler.php

// Добавить функцию asset()
private function compileFunctions(string $content): string
{
    // ... existing functions ...

    // asset() function
    $content = preg_replace_callback(
        '/\{\%\s*asset\([\'"]([^\'"]+)[\'"]\)\s*\%\}/',
        fn($m) => "<?php echo \\Laas\\View\\AssetHelper::asset('{$m[1]}'); ?>",
        $content
    );

    return $content;
}
```

**4. Template Usage**
```html
<!-- themes/admin/layout.html -->
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="{% asset('resources/css/admin.css') %}">
</head>
<body>
    {% block content %}{% endblock %}
    <script src="{% asset('resources/js/admin.js') %}"></script>
</body>
</html>
```

**5. Build Process**
```bash
# Development
npm run dev

# Production
npm run build
```

### Выгода
- **Minification**: 40-60% меньше размер файлов
- **Versioning**: автоматический cache busting
- **Tree shaking**: удаление неиспользуемого кода
- **Legacy support**: автоматические polyfills

---

## 🟢 P5: HTTP/2 Server Push & Preload

**Приоритет**: 🟢 Низкий
**Сложность**: ⭐ Простая
**Эффект**: ✨ Улучшение (10-15% faster initial load)

### Решение

```php
// src/Http/Response.php

public function preload(string $uri, string $as = 'script', bool $push = false): self
{
    $type = $push ? 'preload; nopush' : 'preload';
    $this->header('Link', "<$uri>; rel=$type; as=$as", false);
    return $this;
}

// Usage в контроллерах
public function index(Request $request): Response
{
    return $this->view
        ->make('admin/dashboard')
        ->preload('/dist/admin.css', 'style')
        ->preload('/dist/admin.js', 'script')
        ->preload('/dist/bootstrap.min.css', 'style');
}
```

---

# 2. БЕЗОПАСНОСТЬ (Security)

## 🔴 P1: Two-Factor Authentication (2FA)

**Приоритет**: 🔴 Высокий
**Сложность**: ⭐⭐⭐ Сложная
**Эффект**: 🚀 Критичный (защита от credential theft)

### Решение

**1. Dependencies**
```bash
composer require spomky-labs/otphp
composer require bacon/bacon-qr-code
```

**2. Migration**
```php
// database/migrations/core/20260115_000001_create_2fa_table.php
<?php

return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE user_2fa (
                user_id INT PRIMARY KEY,
                secret VARCHAR(255) NOT NULL,
                enabled BOOLEAN DEFAULT 0,
                backup_codes JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS user_2fa");
    }
};
```

**3. Service**
```php
// src/Auth/TwoFactorService.php
<?php

declare(strict_types=1);

namespace Laas\Auth;

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;

class TwoFactorService
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function getQrCodeSvg(string $secret, string $email): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($email);
        $totp->setIssuer('LAAS CMS');

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);

        return $writer->writeString($totp->getProvisioningUri());
    }

    public function verify(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);
        return $totp->verify($code, null, 1); // 1 период допуска (30 сек)
    }

    public function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8-char код
        }
        return $codes;
    }

    public function enable(int $userId, string $secret, array $backupCodes): bool
    {
        $sql = "
            INSERT INTO user_2fa (user_id, secret, enabled, backup_codes)
            VALUES (:user_id, :secret, 1, :backup_codes)
            ON DUPLICATE KEY UPDATE
                secret = :secret,
                enabled = 1,
                backup_codes = :backup_codes
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'user_id' => $userId,
            'secret' => $secret,
            'backup_codes' => json_encode($backupCodes),
        ]);
    }

    public function disable(int $userId): bool
    {
        $sql = "DELETE FROM user_2fa WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['user_id' => $userId]);
    }

    public function isEnabled(int $userId): bool
    {
        $sql = "SELECT enabled FROM user_2fa WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row && (bool) $row['enabled'];
    }

    public function getSecret(int $userId): ?string
    {
        $sql = "SELECT secret FROM user_2fa WHERE user_id = :user_id AND enabled = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row['secret'] ?? null;
    }
}
```

**4. Controller**
```php
// modules/Users/Controller/TwoFactorController.php
<?php

declare(strict_types=1);

namespace Laas\Modules\Users\Controller;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Laas\Auth\TwoFactorService;

class TwoFactorController
{
    private View $view;
    private TwoFactorService $twoFactor;

    public function __construct(View $view, TwoFactorService $twoFactor)
    {
        $this->view = $view;
        $this->twoFactor = $twoFactor;
    }

    public function setup(Request $request): Response
    {
        $userId = $_SESSION['user_id'];
        $userEmail = $_SESSION['email'];

        $secret = $this->twoFactor->generateSecret();
        $qrCode = $this->twoFactor->getQrCodeSvg($secret, $userEmail);
        $backupCodes = $this->twoFactor->generateBackupCodes();

        $_SESSION['2fa_setup_secret'] = $secret;
        $_SESSION['2fa_setup_backup_codes'] = $backupCodes;

        return $this->view->make('users/2fa_setup', [
            'qr_code' => $qrCode,
            'backup_codes' => $backupCodes,
        ]);
    }

    public function enable(Request $request): Response
    {
        $userId = $_SESSION['user_id'];
        $code = $request->post('code');
        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $backupCodes = $_SESSION['2fa_setup_backup_codes'] ?? [];

        if (!$secret || !$this->twoFactor->verify($secret, $code)) {
            return $this->view->make('users/2fa_setup', [
                'errors' => ['Invalid verification code'],
            ])->withStatus(422);
        }

        $this->twoFactor->enable($userId, $secret, $backupCodes);

        unset($_SESSION['2fa_setup_secret']);
        unset($_SESSION['2fa_setup_backup_codes']);

        return (new Response('', 302))
            ->header('Location', '/admin/profile?2fa=enabled');
    }

    public function verify(Request $request): Response
    {
        $userId = $_SESSION['2fa_user_id'] ?? null;
        $code = $request->post('code');

        if (!$userId) {
            return (new Response('', 302))->header('Location', '/login');
        }

        $secret = $this->twoFactor->getSecret($userId);

        if (!$secret || !$this->twoFactor->verify($secret, $code)) {
            return $this->view->make('users/2fa_challenge', [
                'errors' => ['Invalid verification code'],
            ])->withStatus(422);
        }

        // Успешная 2FA верификация
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['2fa_user_id']);
        unset($_SESSION['2fa_required']);

        return (new Response('', 302))->header('Location', '/admin');
    }
}
```

**5. Modify Login Controller**
```php
// modules/Users/Controller/LoginController.php

public function login(Request $request): Response
{
    // ... password verification ...

    if (password_verify($password, $user['password_hash'])) {
        // Проверить 2FA
        if ($this->twoFactor->isEnabled($user['id'])) {
            $_SESSION['2fa_user_id'] = $user['id'];
            $_SESSION['2fa_required'] = true;

            return $this->view->make('users/2fa_challenge', [
                'email' => $user['email'],
            ]);
        }

        // Normal login без 2FA
        $_SESSION['user_id'] = $user['id'];
        // ...
    }
}
```

**6. Routes**
```php
// modules/Users/routes.php
return [
    // ...
    ['GET',  '/2fa/setup',     [TwoFactorController::class, 'setup']],
    ['POST', '/2fa/enable',    [TwoFactorController::class, 'enable']],
    ['POST', '/2fa/disable',   [TwoFactorController::class, 'disable']],
    ['POST', '/2fa/verify',    [TwoFactorController::class, 'verify']],
];
```

**7. Templates**
```html
<!-- themes/admin/pages/2fa_setup.html -->
{% extends 'admin/layout' %}

{% block content %}
<div class="container mt-5">
    <h2>Настройка двухфакторной аутентификации</h2>

    <div class="row mt-4">
        <div class="col-md-6">
            <h4>1. Отсканируйте QR-код</h4>
            <p>Используйте Google Authenticator, Authy или другое приложение:</p>
            <div class="qr-code">
                {% raw qr_code %}
            </div>
        </div>

        <div class="col-md-6">
            <h4>2. Введите код подтверждения</h4>
            <form method="POST" action="/2fa/enable">
                {% csrf() %}

                {% if errors %}
                <div class="alert alert-danger">
                    {% foreach errors as error %}
                    <div>{% error %}</div>
                    {% endforeach %}
                </div>
                {% endif %}

                <div class="mb-3">
                    <label>6-значный код:</label>
                    <input type="text"
                           name="code"
                           class="form-control"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           required
                           autofocus>
                </div>

                <button type="submit" class="btn btn-primary">Включить 2FA</button>
            </form>
        </div>
    </div>

    <div class="row mt-5">
        <div class="col-12">
            <h4>3. Сохраните резервные коды</h4>
            <div class="alert alert-warning">
                <strong>Важно!</strong> Сохраните эти коды в безопасном месте.
                Они понадобятся, если вы потеряете доступ к телефону.
            </div>
            <div class="backup-codes">
                {% foreach backup_codes as code %}
                <code class="d-block mb-2">{% code %}</code>
                {% endforeach %}
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

```html
<!-- themes/admin/pages/2fa_challenge.html -->
{% extends 'admin/layout' %}

{% block content %}
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h3 class="card-title">Двухфакторная аутентификация</h3>
                    <p>Введите код из приложения аутентификатора:</p>

                    <form method="POST" action="/2fa/verify">
                        {% csrf() %}

                        {% if errors %}
                        <div class="alert alert-danger">
                            {% foreach errors as error %}
                            <div>{% error %}</div>
                            {% endforeach %}
                        </div>
                        {% endif %}

                        <div class="mb-3">
                            <input type="text"
                                   name="code"
                                   class="form-control form-control-lg text-center"
                                   pattern="[0-9]{6}"
                                   maxlength="6"
                                   placeholder="000000"
                                   required
                                   autofocus>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Подтвердить</button>
                    </form>

                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            <a href="/2fa/backup">Использовать резервный код</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

### Выгода
- Защита от украденных паролей
- Compliance с security стандартами (PCI DSS, SOC2)
- Резервные коды для восстановления доступа
- Industry-standard TOTP (совместим с Google Authenticator, Authy, 1Password)

---

## 🔴 P2: Password Policy Enforcement

**Приоритет**: 🔴 Высокий
**Сложность**: ⭐⭐ Средняя
**Эффект**: 🚀 Критичный (защита от weak passwords)

### Решение

**1. Password Policy Class**
```php
// src/Auth/PasswordPolicy.php
<?php

declare(strict_types=1);

namespace Laas\Auth;

use Laas\Core\Validation\ValidationResult;

class PasswordPolicy
{
    private \PDO $pdo;
    private int $minLength;
    private bool $requireUppercase;
    private bool $requireLowercase;
    private bool $requireNumbers;
    private bool $requireSpecial;
    private bool $checkCommon;
    private int $historyCount;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->minLength = config('security.password_policy.min_length', 12);
        $this->requireUppercase = config('security.password_policy.require_uppercase', true);
        $this->requireLowercase = config('security.password_policy.require_lowercase', true);
        $this->requireNumbers = config('security.password_policy.require_numbers', true);
        $this->requireSpecial = config('security.password_policy.require_special', true);
        $this->checkCommon = config('security.password_policy.check_common', true);
        $this->historyCount = config('security.password_policy.history_count', 5);
    }

    public function validate(string $password): ValidationResult
    {
        $errors = [];

        // Length
        if (mb_strlen($password) < $this->minLength) {
            $errors[] = "Password must be at least {$this->minLength} characters";
        }

        // Uppercase
        if ($this->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        // Lowercase
        if ($this->requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        // Numbers
        if ($this->requireNumbers && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        // Special characters
        if ($this->requireSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character (!@#$%^&*)";
        }

        // Common passwords
        if ($this->checkCommon && $this->isCommonPassword($password)) {
            $errors[] = "This password is too common. Please choose a different one";
        }

        return new ValidationResult(empty($errors), $errors);
    }

    public function checkHistory(int $userId, string $newPassword): bool
    {
        $sql = "
            SELECT password_hash
            FROM password_history
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'limit' => $this->historyCount,
        ]);

        $hashes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($hashes as $hash) {
            if (password_verify($newPassword, $hash)) {
                return false; // Password was used recently
            }
        }

        return true;
    }

    public function saveToHistory(int $userId, string $passwordHash): void
    {
        $sql = "
            INSERT INTO password_history (user_id, password_hash)
            VALUES (:user_id, :password_hash)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
        ]);
    }

    private function isCommonPassword(string $password): bool
    {
        // Top 1000 common passwords
        $commonPasswords = [
            'password', '123456', '123456789', 'qwerty', 'abc123',
            'password1', '12345678', '111111', '1234567', 'letmein',
            '1234567890', 'welcome', 'monkey', 'dragon', 'master',
            // ... добавить больше из списка
        ];

        return in_array(strtolower($password), $commonPasswords);
    }

    public function getRequirements(): array
    {
        return [
            'min_length' => $this->minLength,
            'require_uppercase' => $this->requireUppercase,
            'require_lowercase' => $this->requireLowercase,
            'require_numbers' => $this->requireNumbers,
            'require_special' => $this->requireSpecial,
        ];
    }
}
```

**2. Migration**
```php
// database/migrations/core/20260115_000002_create_password_history.php
<?php

return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE password_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE INDEX idx_password_history_user
            ON password_history(user_id, created_at DESC)
        ");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS password_history");
    }
};
```

**3. Config**
```php
// config/security.php
return [
    // ...
    'password_policy' => [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special' => true,
        'check_common' => true,
        'history_count' => 5,        // Нельзя повторить последние 5 паролей
        'expiry_days' => null,       // null = no expiry, 90 = expire after 90 days
    ],
];
```

**4. Integration**
```php
// modules/Users/Controller/RegisterController.php

$passwordPolicy = new PasswordPolicy($this->db);

// Validate password strength
$validation = $passwordPolicy->validate($password);
if (!$validation->isValid()) {
    return $this->view->make('users/register', [
        'errors' => $validation->getErrors(),
        'requirements' => $passwordPolicy->getRequirements(),
    ])->withStatus(422);
}

// Create user
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
$userId = $this->usersRepo->create([...]);

// Save to history
$passwordPolicy->saveToHistory($userId, $passwordHash);
```

```php
// modules/Users/Controller/ChangePasswordController.php

$passwordPolicy = new PasswordPolicy($this->db);

// Validate new password
$validation = $passwordPolicy->validate($newPassword);
if (!$validation->isValid()) {
    return $this->view->make('users/change_password', [
        'errors' => $validation->getErrors(),
    ])->withStatus(422);
}

// Check history
if (!$passwordPolicy->checkHistory($userId, $newPassword)) {
    return $this->view->make('users/change_password', [
        'errors' => ['You cannot reuse a recent password'],
    ])->withStatus(422);
}

// Update password
$passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->usersRepo->update($userId, ['password_hash' => $passwordHash]);

// Save to history
$passwordPolicy->saveToHistory($userId, $passwordHash);
```

**5. Frontend Helper**
```html
<!-- themes/admin/partials/password_requirements.html -->
<div class="password-requirements">
    <p>Password must contain:</p>
    <ul>
        <li id="req-length" class="text-muted">
            At least {% min_length %} characters
        </li>
        <li id="req-uppercase" class="text-muted">
            One uppercase letter (A-Z)
        </li>
        <li id="req-lowercase" class="text-muted">
            One lowercase letter (a-z)
        </li>
        <li id="req-number" class="text-muted">
            One number (0-9)
        </li>
        <li id="req-special" class="text-muted">
            One special character (!@#$%^&*)
        </li>
    </ul>
</div>

<script>
document.getElementById('password').addEventListener('input', function(e) {
    const pwd = e.target.value;

    // Length
    document.getElementById('req-length').className =
        pwd.length >= {% min_length %} ? 'text-success' : 'text-muted';

    // Uppercase
    document.getElementById('req-uppercase').className =
        /[A-Z]/.test(pwd) ? 'text-success' : 'text-muted';

    // Lowercase
    document.getElementById('req-lowercase').className =
        /[a-z]/.test(pwd) ? 'text-success' : 'text-muted';

    // Number
    document.getElementById('req-number').className =
        /[0-9]/.test(pwd) ? 'text-success' : 'text-muted';

    // Special
    document.getElementById('req-special').className =
        /[^A-Za-z0-9]/.test(pwd) ? 'text-success' : 'text-muted';
});
</script>
```

---

## 🔴 P3: Advanced Rate Limiting (Per-User + Adaptive)

**Приоритет**: 🔴 Высокий
**Сложность**: ⭐⭐ Средняя
**Эффект**: 🚀 Критичный (защита от distributed brute force)

### Проблема
Текущий rate limiter только per-IP. Атакующий может:
- Использовать distributed IPs (botnet)
- Bypass через Tor/VPN rotation
- Атаковать конкретный username с разных IP

### Решение

**1. Advanced Rate Limiter**
```php
// src/Security/AdvancedRateLimiter.php
<?php

declare(strict_types=1);

namespace Laas\Security;

use Laas\Cache\CacheInterface;

class AdvancedRateLimiter
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Check multiple rate limit keys for same action
     */
    public function checkMultiple(string $action, array $keys, array $limits): bool
    {
        foreach ($keys as $keyType => $identifier) {
            $limit = $limits[$keyType] ?? $limits['default'] ?? null;

            if (!$limit) {
                continue;
            }

            $fullKey = "{$action}:{$keyType}:{$identifier}";

            if (!$this->check($fullKey, $limit['max'], $limit['window'])) {
                return false;
            }
        }

        return true;
    }

    public function check(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        // Check if banned
        if ($this->isBanned($key)) {
            return false;
        }

        $attempts = $this->cache->get($key) ?? 0;

        if ($attempts >= $maxAttempts) {
            $this->incrementViolations($key);
            return false;
        }

        // Increment attempts
        $this->cache->set($key, $attempts + 1, $decaySeconds);

        return true;
    }

    public function incrementViolations(string $key): void
    {
        $violationsKey = "violations:$key";
        $violations = $this->cache->get($violationsKey) ?? 0;
        $violations++;

        $this->cache->set($violationsKey, $violations, 3600);

        // Exponential backoff ban
        // 3 violations -> 15 min
        // 5 violations -> 1 hour
        // 10 violations -> 24 hours
        if ($violations >= 3) {
            $banTime = min(86400, 900 * pow(2, $violations - 3));
            $this->ban($key, $banTime);
        }
    }

    public function ban(string $key, int $seconds): void
    {
        $this->cache->set("ban:$key", true, $seconds);
    }

    public function isBanned(string $key): bool
    {
        return (bool) $this->cache->get("ban:$key");
    }

    public function reset(string $key): void
    {
        $this->cache->delete($key);
        $this->cache->delete("violations:$key");
        $this->cache->delete("ban:$key");
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        if ($this->isBanned($key)) {
            return 0;
        }

        $attempts = $this->cache->get($key) ?? 0;
        return max(0, $maxAttempts - $attempts);
    }
}
```

**2. Config**
```php
// config/security.php
return [
    // ...
    'rate_limit' => [
        'login' => [
            'per_ip' => ['window' => 60, 'max' => 10],
            'per_username' => ['window' => 60, 'max' => 5],
            'global' => ['window' => 60, 'max' => 100],
        ],
        'api' => [
            'per_ip' => ['window' => 60, 'max' => 60],
            'per_user' => ['window' => 60, 'max' => 120],
        ],
        'media_upload' => [
            'per_user' => ['window' => 300, 'max' => 10],
            'per_ip' => ['window' => 300, 'max' => 20],
        ],
    ],
];
```

**3. Usage в Login**
```php
// modules/Users/Controller/LoginController.php

public function login(Request $request): Response
{
    $username = $request->post('username');
    $password = $request->post('password');

    $rateLimiter = new AdvancedRateLimiter($this->cache);

    $keys = [
        'ip' => $request->getIp(),
        'username' => $username,
    ];

    $limits = config('security.rate_limit.login');

    if (!$rateLimiter->checkMultiple('login', $keys, $limits)) {
        // Log suspicious activity
        $this->logger->warning('Rate limit exceeded for login', [
            'ip' => $request->getIp(),
            'username' => $username,
        ]);

        return $this->view->make('errors/429', [
            'message' => 'Too many login attempts. Please try again later.',
        ])->withStatus(429);
    }

    // ... normal login flow ...
}
```

**4. Middleware для API**
```php
// src/Http/Middleware/ApiRateLimitMiddleware.php
<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\AdvancedRateLimiter;

class ApiRateLimitMiddleware implements MiddlewareInterface
{
    private AdvancedRateLimiter $rateLimiter;

    public function __construct(AdvancedRateLimiter $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $request->getAttribute('api_user_id');

        $keys = [
            'ip' => $request->getIp(),
        ];

        if ($userId) {
            $keys['user'] = $userId;
        }

        $limits = config('security.rate_limit.api');

        if (!$this->rateLimiter->checkMultiple('api', $keys, $limits)) {
            return new Response(
                json_encode(['error' => 'Too Many Requests']),
                429,
                ['Content-Type' => 'application/json']
            );
        }

        $response = $next($request);

        // Add rate limit headers
        $remaining = $this->rateLimiter->remaining(
            'api:ip:' . $request->getIp(),
            $limits['per_ip']['max']
        );

        $response->header('X-RateLimit-Limit', (string) $limits['per_ip']['max']);
        $response->header('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
```

---

*(Документ продолжается в следующем сообщении из-за ограничения длины)*

Продолжить создание документа со следующими разделами:
- P4: Security Headers Enhancement
- P5: CAPTCHA Integration
- Раздел 3: Тестирование (P1-P5)
- Раздел 4: Функциональность (P1-P6)
- Quick Wins
- Roadmap

?
