<?php
declare(strict_types=1);

use Laas\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class SecurityHeadersModeTest extends TestCase
{
    public function testReportOnlyHeaderIsUsed(): void
    {
        $headers = new SecurityHeaders([
            'csp' => [
                'enabled' => true,
                'mode' => 'report-only',
                'directives' => [
                    'default-src' => ["'self'"],
                ],
            ],
        ]);

        $result = $headers->all(true);

        $this->assertArrayHasKey('Content-Security-Policy-Report-Only', $result);
        $this->assertArrayNotHasKey('Content-Security-Policy', $result);
    }
}
