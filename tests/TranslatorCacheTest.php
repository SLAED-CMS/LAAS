<?php
declare(strict_types=1);

use Laas\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorCacheTest extends TestCase
{
    public function testTranslatorLoadsOncePerRequest(): void
    {
        $root = sys_get_temp_dir() . '/laas_i18n_' . bin2hex(random_bytes(4));
        @mkdir($root . '/resources/lang/en', 0775, true);
        $file = $root . '/resources/lang/en/core.php';
        file_put_contents($file, "<?php\nreturn ['hello' => 'Hello'];\n");

        $translator = new Translator($root, 'default', 'en');
        $this->assertSame('Hello', $translator->trans('hello'));

        @unlink($file);
        $this->assertSame('Hello', $translator->trans('hello'));
    }
}
