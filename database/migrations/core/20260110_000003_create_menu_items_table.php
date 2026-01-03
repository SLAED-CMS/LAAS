<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS menu_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  menu_id INT NOT NULL,
  label VARCHAR(150) NOT NULL,
  url VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_menu_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS menu_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  menu_id INT NOT NULL,
  label VARCHAR(150) NOT NULL,
  url VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_menu_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  INDEX idx_menu_items_menu_id (menu_id),
  INDEX idx_menu_items_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        }
        $pdo->exec($sql);
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_menu_items_menu_id ON menu_items (menu_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_menu_items_sort ON menu_items (sort_order)');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS menu_items');
    }
};
