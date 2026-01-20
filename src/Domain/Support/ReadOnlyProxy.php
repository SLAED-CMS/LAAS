<?php
declare(strict_types=1);

namespace Laas\Domain\Support;

use DomainException;
use Laas\Http\RequestContext;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

class ReadOnlyProxy
{
    /** @var null|callable */
    private static $logger = null;

    /** @var array<string, true> */
    private array $allowed;

    /**
     * @param array<int, string> $allowedMethods
     */
    protected function __construct(
        private object $service,
        array $allowedMethods,
        private string $interfaceName
    ) {
        $this->allowed = array_fill_keys($allowedMethods, true);
    }

    public static function wrap(object $service, string $interfaceName): object
    {
        $class = self::generatedClass($interfaceName);
        return new $class($service, self::allowedMethods($service), $interfaceName);
    }

    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
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
            if (RequestContext::isDebug()) {
                self::warnOncePerRequest($this->interfaceName, $method);
            }
            throw new DomainException(
                'Read-only service: mutation method ' . $this->interfaceName . '::' . $method . ' is not allowed'
            );
        }

        return $this->service->{$method}(...$args);
    }

    private static function warnOncePerRequest(string $interfaceName, string $method): void
    {
        $rid = RequestContext::requestId() ?? 'no-rid';
        $path = RequestContext::path() ?? 'no-path';
        $key = $rid . '|' . $path;
        static $seen = [];
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $message = '[ReadOnlyProxy] blocked mutation ' . $interfaceName . '::' . $method
            . ' req=' . $rid . ' path=' . $path;
        if (self::$logger !== null) {
            (self::$logger)($message);
            return;
        }
        error_log($message);
    }

    private static function generatedClass(string $interfaceName): string
    {
        if (!interface_exists($interfaceName)) {
            throw new DomainException('Read-only service: interface not found ' . $interfaceName);
        }

        $hash = substr(sha1($interfaceName), 0, 12);
        $class = __NAMESPACE__ . '\\ReadOnlyProxy_' . $hash;
        if (class_exists($class, false)) {
            return $class;
        }

        $reflection = new ReflectionClass($interfaceName);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodLines = [];
        foreach ($methods as $method) {
            $methodLines[] = self::renderMethod($method);
        }

        $classCode = 'namespace ' . __NAMESPACE__ . ';'
            . ' final class ReadOnlyProxy_' . $hash . ' extends \\'
            . __NAMESPACE__ . '\\ReadOnlyProxy implements \\' . $interfaceName . ' {'
            . implode('', $methodLines)
            . '}';

        eval($classCode);
        return $class;
    }

    private static function renderMethod(ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $params[] = self::renderParameter($parameter);
        }
        $returnType = $method->hasReturnType()
            ? ': ' . self::typeToString($method->getReturnType())
            : '';
        $body = self::renderBody($method);

        return ' public function ' . $method->getName() . '(' . implode(', ', $params) . ')' . $returnType
            . ' {' . $body . ' }';
    }

    private static function renderBody(ReflectionMethod $method): string
    {
        $call = '$this->call(\'' . $method->getName() . '\', func_get_args())';
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType && $returnType->getName() === 'void') {
            return $call . ';';
        }
        return 'return ' . $call . ';';
    }

    private static function renderParameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        $typePrefix = $type !== null ? self::typeToString($type) . ' ' : '';
        $byRef = $parameter->isPassedByReference() ? '&' : '';
        $variadic = $parameter->isVariadic() ? '...' : '';
        $name = '$' . $parameter->getName();

        $default = '';
        if ($parameter->isOptional() && !$parameter->isVariadic()) {
            if ($parameter->isDefaultValueAvailable()) {
                if ($parameter->isDefaultValueConstant()) {
                    $default = ' = ' . $parameter->getDefaultValueConstantName();
                } else {
                    $default = ' = ' . var_export($parameter->getDefaultValue(), true);
                }
            } elseif ($parameter->allowsNull()) {
                $default = ' = null';
            }
        }

        return $typePrefix . $byRef . $variadic . $name . $default;
    }

    private static function typeToString(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeToString($type);
        }
        if ($type instanceof ReflectionUnionType) {
            $parts = array_map([self::class, 'typeToString'], $type->getTypes());
            return implode('|', $parts);
        }
        if ($type instanceof ReflectionIntersectionType) {
            $parts = array_map([self::class, 'typeToString'], $type->getTypes());
            return implode('&', $parts);
        }
        return 'mixed';
    }

    private static function namedTypeToString(ReflectionNamedType $type): string
    {
        $name = $type->getName();
        $isBuiltin = $type->isBuiltin();
        $nullable = $type->allowsNull() && $name !== 'mixed';

        if (!$isBuiltin && !in_array($name, ['self', 'parent', 'static'], true)) {
            $name = '\\' . ltrim($name, '\\');
        }

        return $nullable ? '?' . $name : $name;
    }
}
