<?php
declare(strict_types=1);

use Laas\Theme\TemplateResolver;
use Laas\Theme\ThemeInterface;
use PHPUnit\Framework\TestCase;

final class TemplateResolverTest extends TestCase
{
    public function testResolvesFromThemeViews(): void
    {
        $root = sys_get_temp_dir() . '/laas_theme_' . bin2hex(random_bytes(4));
        $views = $root . '/views';
        mkdir($views, 0775, true);
        $path = $views . '/pages/test.html';
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'ok');

        $theme = new class($views) implements ThemeInterface {
            public function __construct(private string $views)
            {
            }

            public function name(): string
            {
                return 'test';
            }

            public function viewPaths(): array
            {
                return [$this->views];
            }

            public function assets(): array
            {
                return [];
            }
        };

        $resolver = new TemplateResolver();
        $resolved = $resolver->resolve('pages/test.html', $theme);
        $this->assertSame($path, $resolved);
    }

    public function testRejectsTraversal(): void
    {
        $resolver = new TemplateResolver();
        $theme = new class implements ThemeInterface {
            public function name(): string
            {
                return 'test';
            }

            public function viewPaths(): array
            {
                return [];
            }

            public function assets(): array
            {
                return [];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('../secrets.txt', $theme);
    }

    public function testAbsolutePathMustExist(): void
    {
        $resolver = new TemplateResolver();
        $theme = new class implements ThemeInterface {
            public function name(): string
            {
                return 'test';
            }

            public function viewPaths(): array
            {
                return [];
            }

            public function assets(): array
            {
                return [];
            }
        };

        $path = realpath(__FILE__);
        $this->assertIsString($path);
        if (!is_file($path)) {
            $this->markTestSkipped('Absolute path is not readable in this environment.');
        }
        $resolved = $resolver->resolve($path, $theme);
        $this->assertSame($path, $resolved);
    }
}
