<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DevToolsThemeSettingsTest extends TestCase
{
    public function testTerminalThemeDefaults(): void
    {
        $config = require dirname(__DIR__) . '/config/devtools.php';

        $this->assertIsArray($config);
        $this->assertIsArray($config['terminal']);

        $terminal = $config['terminal'];
        $this->assertSame('#1e1f29', $terminal['bg']);
        $this->assertSame('#1b1c25', $terminal['panel_bg']);
        $this->assertSame('#d6d6d6', $terminal['text']);
        $this->assertSame('#8a8da8', $terminal['muted']);
        $this->assertSame('#82aaff', $terminal['info']);
        $this->assertSame('#73c991', $terminal['ok']);
        $this->assertSame('#ffcb6b', $terminal['warn']);
        $this->assertSame('#ff6c6b', $terminal['err']);
        $this->assertSame('#89ddff', $terminal['num']);
        $this->assertSame('#c792ea', $terminal['sql']);
        $this->assertSame(16, $terminal['font_size']);
        $this->assertSame(1.25, $terminal['line_height']);
        $this->assertSame('Verdana, Tahoma, monospace', $terminal['font_family']);
    }
}
