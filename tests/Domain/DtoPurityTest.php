<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DtoPurityTest extends TestCase
{
    public function testDtoPropertiesArePure(): void
    {
        $root = dirname(__DIR__, 2);
        $dtoFiles = $this->findDtoFiles($root . '/src/Domain');
        $this->assertNotEmpty($dtoFiles, 'DTO files not found.');

        $dtoClasses = [];
        foreach ($dtoFiles as $file) {
            $class = $this->classFromFile($file);
            $this->assertNotNull($class, 'Cannot resolve DTO class from ' . $file);
            require_once $file;
            if (class_exists($class)) {
                $dtoClasses[] = ltrim($class, '\\');
            }
        }
        $dtoClasses = array_values(array_unique($dtoClasses));
        $this->assertNotEmpty($dtoClasses, 'DTO classes not resolved.');

        foreach ($dtoClasses as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getProperties() as $property) {
                $this->assertTrue(
                    $property->isPrivate() || $property->isReadOnly(),
                    $class . '::$' . $property->getName() . ' must be private or readonly'
                );

                $type = $property->getType();
                $this->assertNotNull(
                    $type,
                    $class . '::$' . $property->getName() . ' must have a type'
                );

                foreach ($this->typeNames($type) as $typeName) {
                    $normalized = $this->normalizeTypeName($typeName, $class);
                    if ($normalized === 'null') {
                        continue;
                    }
                    $this->assertFalse(
                        $this->isForbiddenNamespace($normalized),
                        $class . '::$' . $property->getName() . ' uses forbidden namespace type ' . $normalized
                    );
                    $this->assertTrue(
                        $this->isAllowedType($normalized, $dtoClasses),
                        $class . '::$' . $property->getName() . ' uses disallowed type ' . $normalized
                    );
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function findDtoFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($path, '/Dto/')) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);
        return $files;
    }

    private function classFromFile(string $file): ?string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $class = '';

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $part[1];
                        continue;
                    }
                    if ($part === ';' || $part === '{') {
                        break;
                    }
                }
                continue;
            }

            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_STRING) {
                    $class = $next[1];
                    break 2;
                }
                if ($next === '{') {
                    break;
                }
            }
        }

        if ($class === '') {
            return null;
        }

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }

    /**
     * @return array<int, string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }
        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $child) {
                $names = array_merge($names, $this->typeNames($child));
            }
            return $names;
        }
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }
        return [];
    }

    private function normalizeTypeName(string $typeName, string $class): string
    {
        $normalized = ltrim($typeName, '\\');
        if ($normalized === 'self' || $normalized === 'static') {
            return ltrim($class, '\\');
        }
        return $normalized;
    }

    private function isForbiddenNamespace(string $typeName): bool
    {
        if (!str_contains($typeName, '\\')) {
            return false;
        }
        return preg_match('/\\\\(Database|Repository|Container|Service|Controller)\\\\/', $typeName) === 1;
    }

    /**
     * @param array<int, string> $dtoClasses
     */
    private function isAllowedType(string $typeName, array $dtoClasses): bool
    {
        $scalars = ['int', 'float', 'string', 'bool', 'array'];
        if (in_array($typeName, $scalars, true)) {
            return true;
        }
        if ($typeName === 'DateTimeImmutable') {
            return true;
        }

        $typeName = ltrim($typeName, '\\');
        return in_array($typeName, $dtoClasses, true);
    }
}
