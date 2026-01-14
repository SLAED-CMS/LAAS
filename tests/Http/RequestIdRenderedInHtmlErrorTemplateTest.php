<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Support\RequestScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class RequestIdRenderedInHtmlErrorTemplateTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testRequestIdRenderedInErrorTemplates(int $status): void
    {
        $requestId = 'req-test-1234';
        $prev = RequestScope::get('request.id');
        RequestScope::set('request.id', $requestId);

        try {
            $pdo = SecurityTestHelper::createSqlitePdo();
            $db = SecurityTestHelper::dbManagerFromPdo($pdo);
            $request = new Request('GET', '/', [], [], [], '');
            $view = SecurityTestHelper::createView($db, $request, 'default');

            $response = $view->render('pages/' . $status . '.html', [
                'message' => 'Error',
                'back_url' => '/previous',
            ], $status);

            $this->assertStringContainsString($requestId, $response->getBody());
        } finally {
            if ($prev === null) {
                RequestScope::forget('request.id');
            } else {
                RequestScope::set('request.id', $prev);
            }
        }
    }

    public static function statusProvider(): array
    {
        return [
            [404],
            [503],
        ];
    }
}
