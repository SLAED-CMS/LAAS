<?php
declare(strict_types=1);

use Laas\Core\Kernel;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class SanityFailureTest extends TestCase
{
    public function testConfigSanityErrorReturnsGeneric500(): void
    {
        $root = dirname(__DIR__);
        $backup = $_ENV['STORAGE_DISK'] ?? null;
        $_ENV['STORAGE_DISK'] = 'invalid_disk';

        try {
            $kernel = new Kernel($root);
            $response = $kernel->handle(new Request('GET', '/', [], [], [], ''));

            $this->assertSame(500, $response->getStatus());
            $this->assertSame('Error', $response->getBody());
        } finally {
            if ($backup === null) {
                unset($_ENV['STORAGE_DISK']);
            } else {
                $_ENV['STORAGE_DISK'] = $backup;
            }
        }
    }
}
