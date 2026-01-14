<?php
declare(strict_types=1);

use Laas\Database\DbSqlFingerprint;
use PHPUnit\Framework\TestCase;

final class DbFingerprintTest extends TestCase
{
    public function testFingerprintIgnoresLiterals(): void
    {
        $a = DbSqlFingerprint::fingerprint("SELECT * FROM users WHERE id = 1 AND email = 'a@example.com'");
        $b = DbSqlFingerprint::fingerprint("SELECT * FROM users WHERE id = 2 AND email = 'b@example.com'");

        $this->assertSame($a, $b);
    }

    public function testFingerprintDiffersByStructure(): void
    {
        $a = DbSqlFingerprint::fingerprint("SELECT * FROM users WHERE id = 1");
        $b = DbSqlFingerprint::fingerprint("SELECT * FROM users WHERE email = 'x@example.com'");

        $this->assertNotSame($a, $b);
    }
}
