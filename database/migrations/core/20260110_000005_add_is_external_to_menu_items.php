<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE menu_items ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE menu_items DROP COLUMN is_external');
    }
};
