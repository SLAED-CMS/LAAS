<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS permission_role (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_permission_role_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_permission_role_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
)
SQL;
        $pdo->exec($sql);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS permission_role');
    }
};
