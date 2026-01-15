<?php
declare(strict_types=1);

namespace Laas\Ai;

final class PlanRunner
{
    private string $rootPath;
    /** @var array<int, string> */
    private array $allowlist;

    /**
     * @param array<int, string>|null $allowlist
     */
    public function __construct(?string $rootPath = null, ?array $allowlist = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 2);
        if ($allowlist === null) {
            $allowlist = $this->loadAllowlistFromConfig($this->rootPath);
        }
        $this->allowlist = $allowlist ?? [
            'policy:check',
            'templates:raw:check',
            'ai:proposal:validate',
            'contracts:check',
            'preflight',
        ];
    }

    /**
     * @return array{
     *   steps_total:int,
     *   steps_run:int,
     *   failed:int,
     *   outputs: array<int, array{
     *     id:string,
     *     title:string,
     *     command:string,
     *     args:array<int, string>,
     *     status:string,
     *     exit_code:int|null,
     *     stdout:string,
     *     stderr:string
     *   }>,
     *   refused:bool
     * }
     */
    public function run(Plan $plan, bool $dryRun, bool $yes): array
    {
        $payload = $plan->toArray();
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];

        $result = [
            'steps_total' => count($steps),
            'steps_run' => 0,
            'failed' => 0,
            'outputs' => [],
            'refused' => false,
        ];

        if (!$dryRun && !$yes) {
            $result['refused'] = true;
            return $result;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                $result['failed']++;
                $result['outputs'][] = [
                    'id' => '',
                    'title' => '',
                    'command' => '',
                    'args' => [],
                    'status' => 'invalid_step',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => 'step_invalid',
                ];
                continue;
            }

            $stepId = (string) ($step['id'] ?? '');
            $title = (string) ($step['title'] ?? '');
            $command = (string) ($step['command'] ?? '');
            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            $output = [
                'id' => $stepId,
                'title' => $title,
                'command' => $command,
                'args' => $args,
                'status' => '',
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
            ];

            if (!in_array($command, $this->allowlist, true)) {
                $output['status'] = 'blocked';
                $output['stderr'] = 'command_not_allowlisted';
                $result['failed']++;
                $result['outputs'][] = $output;
                continue;
            }

            $badArg = $this->findUnsafeArg($args);
            if ($badArg !== null) {
                $output['status'] = 'invalid_args';
                $output['stderr'] = 'unsafe_arg: ' . $badArg;
                $result['failed']++;
                $result['outputs'][] = $output;
                continue;
            }

            if ($dryRun) {
                $output['status'] = 'dry-run';
                $result['outputs'][] = $output;
                continue;
            }

            [$code, $stdout, $stderr] = $this->runCli($command, $args);
            $output['exit_code'] = $code;
            $output['stdout'] = $this->truncate((string) $stdout);
            $output['stderr'] = $this->truncate((string) $stderr);
            $output['status'] = $code === 0 ? 'ok' : 'failed';

            $result['steps_run']++;
            if ($code !== 0) {
                $result['failed']++;
            }
            $result['outputs'][] = $output;
        }

        return $result;
    }

    /**
     * @param array<int, string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function runCli(string $command, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, $this->rootPath . '/tools/cli.php', $command], $args);

        if (function_exists('proc_open')) {
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptors, $pipes, $this->rootPath, $_ENV);
            if (is_resource($process)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                $code = proc_close($process);

                return [(int) $code, (string) $stdout, (string) $stderr];
            }
        }

        $stdoutPath = tempnam(sys_get_temp_dir(), 'laas-plan-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'laas-plan-err-');
        if ($stdoutPath === false || $stderrPath === false) {
            return [1, '', 'failed_to_create_temp_files'];
        }

        $parts = array_map('escapeshellarg', $cmd);
        $commandLine = implode(' ', $parts) . ' 1> ' . escapeshellarg($stdoutPath) . ' 2> ' . escapeshellarg($stderrPath);
        $output = [];
        $code = 0;
        exec($commandLine, $output, $code);

        $stdout = is_file($stdoutPath) ? (string) file_get_contents($stdoutPath) : '';
        $stderr = is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '';
        @unlink($stdoutPath);
        @unlink($stderrPath);

        return [(int) $code, $stdout, $stderr];
    }

    /**
     * @param array<int, string> $args
     */
    private function findUnsafeArg(array $args): ?string
    {
        foreach ($args as $arg) {
            $value = (string) $arg;
            if (str_contains($value, '&&') || str_contains($value, '|') || str_contains($value, ';') || str_contains($value, '>') || str_contains($value, '<')) {
                return $value;
            }
        }

        return null;
    }

    private function truncate(string $value, int $limit = 2000): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit);
    }

    private function loadAllowlistFromConfig(string $root): ?array
    {
        $path = $root . '/config/security.php';
        if (!is_file($path)) {
            return null;
        }
        $config = require $path;
        if (!is_array($config)) {
            return null;
        }
        $allowlist = $config['ai_plan_command_allowlist'] ?? null;
        if (!is_array($allowlist)) {
            return null;
        }

        return $allowlist;
    }
}
