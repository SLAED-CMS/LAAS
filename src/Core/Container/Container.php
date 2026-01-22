<?php

declare(strict_types=1);

namespace Laas\Core\Container;

final class Container
{
    /** @var array<string, array{concrete: callable|string, singleton: bool, meta: array<string, mixed>}> */
    private array $bindings = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, true> */
    private array $duplicates = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function bind(string $id, callable|string $concrete, array $meta = []): void
    {
        if (isset($this->bindings[$id])) {
            $this->duplicates[$id] = true;
        }
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'singleton' => false,
            'meta' => $meta,
        ];
        unset($this->instances[$id]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function singleton(string $id, callable|string $concrete, array $meta = []): void
    {
        if (isset($this->bindings[$id])) {
            $this->duplicates[$id] = true;
        }
        $this->bindings[$id] = [
            'concrete' => $concrete,
            'singleton' => true,
            'meta' => $meta,
        ];
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->bindings[$id])) {
            throw new NotFoundException('Container binding not found: ' . $id);
        }

        if ($this->bindings[$id]['singleton'] && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $instance = $this->build($this->bindings[$id]['concrete']);
        if ($this->bindings[$id]['singleton']) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    private function build(callable|string $concrete): mixed
    {
        if (is_string($concrete) && class_exists($concrete)) {
            return new $concrete();
        }

        if (is_callable($concrete)) {
            return $concrete();
        }

        throw new ContainerException('Container binding is not instantiable.');
    }

    /**
     * @return array<string, array{concrete: callable|string, singleton: bool, meta: array<string, mixed>}>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * @return array<int, string>
     */
    public function duplicates(): array
    {
        return array_keys($this->duplicates);
    }
}
