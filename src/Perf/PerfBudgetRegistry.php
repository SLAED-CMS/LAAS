<?php

declare(strict_types=1);

namespace Laas\Perf;

use Laas\Http\Request;

final class PerfBudgetRegistry
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(string $rootPath): self
    {
        $path = rtrim($rootPath, '/\\') . '/config/perf_budgets.php';
        $config = is_file($path) ? require $path : [];
        $config = is_array($config) ? $config : [];
        return new self($config);
    }

    public function budgetForRequest(Request $request): ?PerfBudget
    {
        $path = $request->getPath();
        $route = $request->getAttribute('route.pattern');
        $route = is_string($route) ? $route : null;
        return $this->budgetForPath($path, $route);
    }

    public function budgetForPath(string $path, ?string $routePattern = null): ?PerfBudget
    {
        $profile = $this->resolveProfile();
        $profiles = $this->config['profiles'] ?? [];
        if (!is_array($profiles) || !isset($profiles[$profile]) || !is_array($profiles[$profile])) {
            return null;
        }

        $data = $profiles[$profile];
        $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
        $routes = is_array($data['routes'] ?? null) ? $data['routes'] : [];

        $budget = $this->matchBudget($routes, $routePattern) ?? $this->matchBudget($routes, $path);
        if ($budget === null) {
            $budget = $defaults;
        } else {
            $budget = array_merge($defaults, $budget);
        }

        return $budget !== [] ? new PerfBudget($budget) : null;
    }

    private function resolveProfile(): string
    {
        $env = $this->resolveEnvProfile();
        $profiles = $this->config['profiles'] ?? [];
        if ($env !== '' && is_array($profiles) && isset($profiles[$env]) && is_array($profiles[$env])) {
            return $env;
        }
        $default = $this->config['default_profile'] ?? null;
        if (is_string($default) && $default !== '' && is_array($profiles) && isset($profiles[$default])) {
            return $default;
        }
        return is_string($default) && $default !== '' ? $default : 'default';
    }

    private function resolveEnvProfile(): string
    {
        $env = $_ENV['POLICY_PERF_PROFILE'] ?? getenv('POLICY_PERF_PROFILE');
        $env = is_string($env) ? trim($env) : '';
        if ($env !== '') {
            return $env;
        }
        $legacy = $_ENV['PERF_BUDGET_PROFILE'] ?? getenv('PERF_BUDGET_PROFILE');
        return is_string($legacy) ? trim($legacy) : '';
    }

    /**
     * @param array<string, mixed> $routes
     * @return array<string, float|int>|null
     */
    private function matchBudget(array $routes, ?string $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        foreach ($routes as $pattern => $budget) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (!$this->matchesPattern($value, $pattern)) {
                continue;
            }
            if (!is_array($budget)) {
                return null;
            }
            return $budget;
        }

        return null;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }
        if (!str_contains($pattern, '*')) {
            return $value === $pattern;
        }

        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $value);
    }
}
