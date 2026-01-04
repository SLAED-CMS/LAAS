<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Http\Request;
use Laas\Modules\Media\Controller\MediaThumbController;
use Laas\Modules\Pages\Controller\PagesController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class PathTraversalSecurityTest extends TestCase
{
    public function testPagesSlugTraversalReturns404(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedPagesTable($pdo);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $request = new Request('GET', '/pages/../etc/passwd', [], [], [], '');
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $controller = new PagesController($view, $db);

        $response = $controller->show($request, ['slug' => '../etc/passwd']);
        $this->assertSame(404, $response->getStatus());
    }

    public function testMediaThumbTraversalReturns404(): void
    {
        $this->withEnv(['MEDIA_PUBLIC_MODE' => 'all'], function (): void {
            $pdo = SecurityTestHelper::createSqlitePdo();
            SecurityTestHelper::seedMediaTable($pdo);
            $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, created_at, is_public) VALUES (1, 'u', 'uploads/2026/01/u.jpg', 'file.jpg', 'image/jpeg', 4, 'hash', '2026-01-01 00:00:00', 1)");
            $db = SecurityTestHelper::dbManagerFromPdo($pdo);

            $request = new Request('GET', '/media/1/thumb/../etc', [], [], [], '');
            $controller = new MediaThumbController($db);

            $response = $controller->serve($request, ['id' => 1, 'variant' => '../etc']);
            $this->assertSame(404, $response->getStatus());
        });
    }

    private function withEnv(array $vars, callable $callback): void
    {
        $backup = [];
        foreach ($vars as $key => $value) {
            $backup[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = (string) $value;
        }

        try {
            $callback();
        } finally {
            foreach ($vars as $key => $_) {
                if ($backup[$key] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $backup[$key];
                }
            }
        }
    }
}
