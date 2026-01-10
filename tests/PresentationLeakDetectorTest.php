<?php
declare(strict_types=1);

use Laas\Ui\PresentationLeakDetector;
use PHPUnit\Framework\TestCase;

final class PresentationLeakDetectorTest extends TestCase
{
    public function testDetectsPresentationKeys(): void
    {
        $warnings = PresentationLeakDetector::detectArray([
            'foo_class' => 'x',
            'onclick' => 'do()',
            'hx_get' => '/path',
            'style_attr' => 'color:red',
            'nested' => [
                'class_name' => 'y',
                'data-bs-target' => '#id',
            ],
        ]);

        $this->assertGreaterThanOrEqual(4, count($warnings));
    }
}
