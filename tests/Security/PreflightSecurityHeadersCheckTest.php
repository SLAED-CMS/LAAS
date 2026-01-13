<?php
declare(strict_types=1);

use Laas\Ops\Checks\SecurityHeadersCheck;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class PreflightSecurityHeadersCheckTest extends TestCase
{
    public function testDetectsInvalidHeadersConfig(): void
    {
        $check = new SecurityHeadersCheck([
            'frame_options' => '',
            'csp' => [
                'enabled' => true,
                'mode' => 'enforce',
                'directives' => [],
            ],
        ], true);

        $result = $check->run();

        $this->assertSame(1, $result['code']);
        $this->assertNotEmpty($result['errors']);
    }
}
