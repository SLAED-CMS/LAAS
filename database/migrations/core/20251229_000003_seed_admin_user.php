<?php
declare(strict_types=1);

use PDO;

$context = $context ?? [];
$app = $context['app'] ?? [];
$seedPassword = (string) ($app['admin_seed_password'] ?? 'change-me');
$seedEnabled = (bool) ($app['admin_seed_enabled'] ?? true);
$debug = (bool) ($app['debug'] ?? false);

return new class($seedPassword, $seedEnabled, $debug, $context) {
    public function __construct(
        private string $seedPassword,
        private bool $seedEnabled,
        private bool $debug,
        private array $context
    )
    {
    }

    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
        $stmt->execute(['username' => 'admin']);
        $exists = $stmt->fetch();
        if ($exists) {
            return;
        }

        if (!$this->seedEnabled) {
            $this->warn('Admin seed disabled by config.');
            return;
        }

        $passwordIsDefault = $this->seedPassword === '' || $this->seedPassword === 'change-me';
        if (!$this->debug && $passwordIsDefault) {
            $this->warn('Admin seed skipped (prod + default password).');
            return;
        }

        if ($this->debug && $passwordIsDefault) {
            $this->warn('Admin seed uses default password (dev only).');
        }

        $hash = password_hash($this->seedPassword, PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, status, created_at, updated_at)
             VALUES (:username, :email, :password_hash, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'username' => 'admin',
            'email' => 'admin@local',
            'password_hash' => $hash,
            'status' => 1,
        ]);
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare('DELETE FROM users WHERE username = :username');
        $stmt->execute(['username' => 'admin']);
    }

    private function warn(string $message): void
    {
        $logger = $this->context['logger'] ?? null;
        if (is_object($logger) && method_exists($logger, 'warning')) {
            $logger->warning($message);
            return;
        }

        if (!empty($this->context['is_cli'])) {
            echo $message . "\n";
        }
    }
};
