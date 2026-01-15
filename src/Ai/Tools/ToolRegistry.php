<?php
declare(strict_types=1);

namespace Laas\Ai\Tools;

final class ToolRegistry
{
    private array $tools;

    public function __construct(private array $securityConfig)
    {
        $this->tools = $this->buildTools();
    }

    /**
     * @return array<int, array{name: string, args: array<int, string>, mode: string, description: string}>
     */
    public function list(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, array{name: string, args: array<int, string>, mode: string, description: string}>
     */
    private function buildTools(): array
    {
        $allowlist = $this->securityConfig['ai_plan_command_allowlist'] ?? [];
        if (!is_array($allowlist)) {
            $allowlist = [];
        }

        $tools = [];
        foreach ($allowlist as $command) {
            if (!is_string($command) || $command === '') {
                continue;
            }
            $tools[] = [
                'name' => $command,
                'args' => $this->argsFor($command),
                'mode' => 'dry-run',
                'description' => $this->descriptionFor($command),
            ];
        }

        return $tools;
    }

    /**
     * @return array<int, string>
     */
    private function argsFor(string $command): array
    {
        return match ($command) {
            'templates:raw:check' => ['--path', '--allowlist', '--update'],
            'templates:raw:scan' => ['--path', '--json'],
            'theme:validate' => [],
            'policy:check' => [],
            'contracts:check' => [],
            'preflight' => ['--no-tests', '--no-db', '--strict'],
            default => [],
        };
    }

    private function descriptionFor(string $command): string
    {
        return match ($command) {
            'policy:check' => 'Run policy checks (no writes).',
            'templates:raw:check' => 'Compare raw template usage against allowlist.',
            'templates:raw:scan' => 'Scan templates for raw usage.',
            'theme:validate' => 'Validate theme structure.',
            'contracts:check' => 'Validate API contracts and fixtures.',
            'preflight' => 'Run preflight checks (read-only by default).',
            default => 'Internal CLI command.',
        };
    }
}
