<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\Security\Csrf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class CsrfSecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
    }

    public function testPostWithoutCsrfRejected(): void
    {
        $session = new InMemorySession();
        $session->start();
        $middleware = new CsrfMiddleware();
        $request = new Request('POST', '/admin/media/upload', [], [], [], '');
        $request->setSession($session);

        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));
        $this->assertSame(419, $response->getStatus());
    }

    public function testInvalidCsrfRejected(): void
    {
        $session = new InMemorySession();
        $session->start();
        (new Csrf($session))->getToken();
        $middleware = new CsrfMiddleware();

        $request = new Request('POST', '/admin/media/upload', [], [
            '_token' => 'invalid',
        ], [], '');
        $request->setSession($session);
        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));
        $this->assertSame(419, $response->getStatus());
    }

    public function testReadOnlyAllowsCsrfEndpoint(): void
    {
        $translator = new Translator($this->rootPath, 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator, null);
        $request = new Request('POST', '/csrf', [], [], [], '');

        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));
        $this->assertSame(200, $response->getStatus());
    }
}
