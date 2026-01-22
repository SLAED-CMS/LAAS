<?php

declare(strict_types=1);

namespace Laas\Support;

final class UrlPolicy
{
    /** @var array<int, string> */
    private array $allowedSchemes;
    /** @var array<int, string> */
    private array $allowedHostSuffixes;
    /** @var array<int, string> */
    private array $blockedHostSuffixes;
    private bool $allowPrivateIps;
    private bool $allowIpLiteral;
    private bool $blockLocalHostnames;
    /** @var null|callable */
    private $resolver;

    /**
     * @param array<int, string> $allowedSchemes
     * @param array<int, string> $allowedHostSuffixes
     * @param array<int, string> $blockedHostSuffixes
     * @param null|callable $resolver
     */
    public function __construct(
        array $allowedSchemes = ['http', 'https'],
        array $allowedHostSuffixes = [],
        bool $allowPrivateIps = false,
        bool $allowIpLiteral = false,
        bool $blockLocalHostnames = true,
        array $blockedHostSuffixes = ['localhost', '.local', '.internal'],
        ?callable $resolver = null
    ) {
        $this->allowedSchemes = $this->normalizeList($allowedSchemes);
        $this->allowedHostSuffixes = $this->normalizeHostList($allowedHostSuffixes);
        $this->blockedHostSuffixes = $this->normalizeHostList($blockedHostSuffixes);
        $this->allowPrivateIps = $allowPrivateIps;
        $this->allowIpLiteral = $allowIpLiteral;
        $this->blockLocalHostnames = $blockLocalHostnames;
        $this->resolver = $resolver;
    }

    /** @return array<int, string> */
    public function allowedSchemes(): array
    {
        return $this->allowedSchemes;
    }

    /** @return array<int, string> */
    public function allowedHostSuffixes(): array
    {
        return $this->allowedHostSuffixes;
    }

    /** @return array<int, string> */
    public function blockedHostSuffixes(): array
    {
        return $this->blockedHostSuffixes;
    }

    public function allowPrivateIps(): bool
    {
        return $this->allowPrivateIps;
    }

    public function allowIpLiteral(): bool
    {
        return $this->allowIpLiteral;
    }

    public function blockLocalHostnames(): bool
    {
        return $this->blockLocalHostnames;
    }

    /** @return null|callable */
    public function resolver(): ?callable
    {
        return $this->resolver;
    }

    /** @param array<int, string> $values */
    private function normalizeList(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
    }

    /** @param array<int, string> $values */
    private function normalizeHostList(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            $value = trim($value, ". \t\n\r\0\x0B");
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
    }
}
