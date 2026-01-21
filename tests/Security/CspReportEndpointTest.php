<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Database\DatabaseManager;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\System\Controller\CspReportController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class CspReportEndpointTest extends TestCase
{
    public function testStoresSanitizedReport(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.10';

        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE security_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            document_uri TEXT NOT NULL,
            violated_directive TEXT NOT NULL,
            blocked_uri TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            ip TEXT NOT NULL,
            request_id TEXT NULL,
            triaged_at DATETIME NULL,
            ignored_at DATETIME NULL
        )');

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $root = SecurityTestHelper::rootPath();
        $translator = new Translator($root, 'default', 'en');
        $service = new SecurityReportsService($db);
        $controller = new CspReportController(null, $service, null, $translator, new NullLogger());

        $payload = [
            'csp-report' => [
                'document-uri' => 'https://example.test/page?token=secret#frag',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.test/blocked.js?x=1',
            ],
        ];
        $request = new Request(
            'POST',
            '/__csp/report',
            [],
            [],
            ['content-type' => 'application/json', 'user-agent' => 'TestAgent/1.0'],
            json_encode($payload)
        );

        $response = $controller->report($request);

        $this->assertSame(204, $response->getStatus());

        $row = $pdo->query('SELECT * FROM security_reports')->fetch();
        $this->assertIsArray($row);
        $this->assertSame('csp', $row['type']);
        $this->assertSame('new', $row['status']);
        $this->assertSame('https://example.test/page', $row['document_uri']);
        $this->assertSame('script-src', $row['violated_directive']);
        $this->assertSame('https://evil.test/blocked.js', $row['blocked_uri']);
        $this->assertSame('TestAgent/1.0', $row['user_agent']);
        $this->assertSame('127.0.0.10', $row['ip']);
    }
}
