<?php

declare(strict_types=1);

namespace Laas\Ai;

use InvalidArgumentException;
use RuntimeException;

final class PlanStore
{
    private string $rootPath;
    private string $dir;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 2);
        $this->dir = rtrim($this->rootPath, '/\\') . '/storage/plans';
    }

    public function save(Plan $plan): string
    {
        $this->ensureDir();
        $data = $plan->toArray();
        $id = (string) ($data['id'] ?? '');
        $this->assertValidId($id);

        $path = $this->dir . '/' . $id . '.json';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode plan JSON.');
        }
        file_put_contents($path, $json . "\n");

        return $path;
    }

    public function load(string $id): Plan
    {
        $id = trim($id);
        $this->assertValidId($id);
        $path = $this->dir . '/' . $id . '.json';
        if (!is_file($path)) {
            throw new InvalidArgumentException('Plan not found: ' . $id);
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid plan JSON.');
        }

        return Plan::fromArray($data);
    }

    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Failed to create plan storage directory.');
        }
    }

    private function assertValidId(string $id): void
    {
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Invalid plan id.');
        }
    }
}
