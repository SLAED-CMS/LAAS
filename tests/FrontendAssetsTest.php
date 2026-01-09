<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendAssetsTest extends TestCase
{
    public function testTemplatesHaveNoInlineStylesOrScripts(): void
    {
        $themesPath = dirname(__DIR__) . '/themes';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themesPath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'html') {
                continue;
            }

            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            if (stripos($contents, '<style') !== false) {
                $this->fail('Inline <style> found in ' . $path);
            }

            if (preg_match('/<script\\b(?![^>]*\\bsrc=)[^>]*>/i', $contents) === 1) {
                $this->fail('Inline <script> found in ' . $path);
            }

            if (preg_match('/\\sstyle\\s*=\\s*["\\\']/', $contents) === 1) {
                $this->fail('Inline style attribute found in ' . $path);
            }
        }

        $this->assertTrue(true);
    }
}
