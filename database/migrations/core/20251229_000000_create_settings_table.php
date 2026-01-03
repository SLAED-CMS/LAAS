<?php
declare(strict_types=1);

$context = $context ?? [];
$app = $context['app'] ?? [];

return new class($app) {
    public function __construct(private array $app)
    {
    }

    public function up(\PDO $pdo): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(190) PRIMARY KEY,
    `value` TEXT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT 'string',
    `updated_at` DATETIME NOT NULL
)
SQL;
        $pdo->exec($sql);

        $defaults = [
            'site_name' => 'LAAS CMS',
            'default_locale' => (string) ($this->app['default_locale'] ?? 'en'),
            'theme' => (string) ($this->app['theme'] ?? 'default'),
        ];

        $now = $this->now();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                'INSERT INTO settings ("key", "value", "type", "updated_at") VALUES (:key, :value, :type, :updated_at)
                 ON CONFLICT("key") DO UPDATE SET "value" = excluded."value", "type" = excluded."type", "updated_at" = excluded."updated_at"'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (`key`, `value`, `type`, `updated_at`) VALUES (:key, :value, :type, :updated_at)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), `updated_at` = VALUES(`updated_at`)'
            );
        }

        foreach ($defaults as $key => $value) {
            $stmt->execute([
                'key' => $key,
                'value' => (string) $value,
                'type' => 'string',
                'updated_at' => $now,
            ]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS settings');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
};
