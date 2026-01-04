<?php
declare(strict_types=1);

namespace Tests\Security\Support;

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Session\SessionManager;
use Laas\I18n\Translator;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PDO;
use ReflectionProperty;

final class SecurityTestHelper
{
    public static function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    public static function dbManagerFromPdo(PDO $pdo): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);
        return $db;
    }

    public static function startSession(string $rootPath, array $config = []): void
    {
        $manager = new SessionManager($rootPath, ['session' => $config]);
        $manager->start();
    }

    public static function createView(DatabaseManager $db, Request $request, string $theme, string $locale = 'en'): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => $locale,
            'theme' => $theme,
        ], ['site_name', 'default_locale', 'theme']);

        $root = self::rootPath();
        $themeManager = new ThemeManager($root . '/themes', $theme, $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates',
            false
        );
        $translator = new Translator($root, $theme, $locale);
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            $locale,
            ['name' => 'LAAS', 'debug' => false],
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }

    public static function seedRbacTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password_hash TEXT, status INTEGER, last_login_at TEXT, last_login_ip TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');
    }

    public static function seedPagesTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, created_at TEXT, updated_at TEXT)');
    }

    public static function seedMediaTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL
        )');
    }

    public static function seedMenusTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE menu_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            menu_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            url TEXT NOT NULL,
            sort_order INTEGER NOT NULL,
            enabled INTEGER NOT NULL,
            is_external INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
    }

    public static function seedAuditTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            action TEXT NOT NULL,
            entity TEXT NULL,
            entity_id INTEGER NULL,
            context TEXT NULL,
            created_at TEXT NOT NULL
        )');
    }

    public static function insertUser(PDO $pdo, int $id, string $username, string $passwordHash, int $status = 1): void
    {
        $stmt = $pdo->prepare('INSERT INTO users (id, username, password_hash, status, created_at, updated_at) VALUES (:id, :username, :hash, :status, :created_at, :updated_at)');
        $stmt->execute([
            'id' => $id,
            'username' => $username,
            'hash' => $passwordHash,
            'status' => $status,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public static function insertRole(PDO $pdo, int $id, string $name): void
    {
        $stmt = $pdo->prepare('INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (:id, :name, :title, :created_at, :updated_at)');
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'title' => $name,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public static function insertPermission(PDO $pdo, int $id, string $name): void
    {
        $stmt = $pdo->prepare('INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (:id, :name, :title, :created_at, :updated_at)');
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'title' => $name,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public static function assignRole(PDO $pdo, int $userId, int $roleId): void
    {
        $stmt = $pdo->prepare('INSERT INTO role_user (user_id, role_id) VALUES (:user_id, :role_id)');
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    public static function grantPermission(PDO $pdo, int $roleId, int $permId): void
    {
        $stmt = $pdo->prepare('INSERT INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)');
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }
}
