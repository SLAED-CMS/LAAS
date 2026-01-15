<?php
declare(strict_types=1);

namespace Laas\Ai;

use InvalidArgumentException;
use RuntimeException;

final class ProposalStore
{
    private string $rootPath;
    private string $dir;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 2);
        $this->dir = rtrim($this->rootPath, '/\\') . '/storage/proposals';
    }

    public function save(Proposal $proposal): string
    {
        $this->ensureDir();
        $data = $proposal->toArray();
        $id = (string) ($data['id'] ?? '');
        $this->assertValidId($id);

        $path = $this->dir . '/' . $id . '.json';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode proposal JSON.');
        }
        file_put_contents($path, $json . "\n");

        return $path;
    }

    public function load(string $id): Proposal
    {
        $id = trim($id);
        $this->assertValidId($id);
        $path = $this->dir . '/' . $id . '.json';
        if (!is_file($path)) {
            throw new InvalidArgumentException('Proposal not found: ' . $id);
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid proposal JSON.');
        }

        return Proposal::fromArray($data);
    }

    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Failed to create proposal storage directory.');
        }
    }

    private function assertValidId(string $id): void
    {
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new InvalidArgumentException('Invalid proposal id.');
        }
    }
}
