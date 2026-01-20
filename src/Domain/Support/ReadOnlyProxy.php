<?php
declare(strict_types=1);

namespace Laas\Domain\Support;

use DomainException;
use ReflectionClass;
use ReflectionMethod;

class ReadOnlyProxy
{
    /** @var array<string, true> */
    private array $allowed;

    /**
     * @param array<int, string> $allowedMethods
     */
    public function __construct(protected object $service, array $allowedMethods)
    {
        $this->allowed = array_fill_keys($allowedMethods, true);
    }

    /**
     * @return array<int, string>
     */
    public static function allowedMethods(object $service): array
    {
        $reflection = new ReflectionClass($service);
        $allowed = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                continue;
            }
            $name = $method->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }
            $doc = $method->getDocComment() ?: '';
            if (stripos($doc, '@mutation') !== false) {
                continue;
            }
            $allowed[] = $name;
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);
        return $allowed;
    }

    /**
     * @param array<int, mixed> $args
     */
    protected function call(string $method, array $args): mixed
    {
        if (!isset($this->allowed[$method])) {
            throw new DomainException('Read-only service: mutation method ' . $method . ' is not allowed');
        }

        return $this->service->{$method}(...$args);
    }
}
