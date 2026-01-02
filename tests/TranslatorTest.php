<?php
declare(strict_types=1);

use Laas\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testTransReturnsValue(): void
    {
        $rootPath = dirname(__DIR__);
        $translator = new Translator($rootPath, 'default', 'en');

        $this->assertSame('LAAS', $translator->trans('app.name'));
    }

    public function testTransFallbacksToKey(): void
    {
        $rootPath = dirname(__DIR__);
        $translator = new Translator($rootPath, 'default', 'en');

        $this->assertSame('missing.key', $translator->trans('missing.key'));
    }
}
