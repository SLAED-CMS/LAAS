<?php
declare(strict_types=1);

use Laas\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HtmlErrorTemplatesSmokeTest extends TestCase
{
    #[DataProvider('templatesProvider')]
    public function testErrorTemplateRenders(string $theme, int $status): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $path = $theme === 'admin' ? '/admin' : '/';

        $request = new Request('GET', $path, [], [], [], '');
        $view = SecurityTestHelper::createView($db, $request, $theme);
        $options = $theme === 'admin' ? ['theme' => 'admin'] : [];

        $response = $view->render('pages/' . $status . '.html', [
            'message' => 'Error',
        ], $status, [], $options);

        $this->assertSame($status, $response->getStatus());
        $this->assertNotSame('', trim($response->getBody()));
    }

    public static function templatesProvider(): array
    {
        $codes = [400, 401, 403, 404, 413, 414, 429, 431, 503];
        $cases = [];
        foreach (['default', 'admin'] as $theme) {
            foreach ($codes as $code) {
                $cases[] = [$theme, $code];
            }
        }

        return $cases;
    }
}
