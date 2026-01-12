<?php
declare(strict_types=1);

namespace Laas\Support;

final class PreflightRunner
{
    /**
     * @param array<int, array{label: string, enabled: bool, run: callable}> $steps
     * @return array{code: int, results: array<int, array{label: string, status: string}>}
     */
    public function run(array $steps): array
    {
        $results = [];
        $ok = true;

        foreach ($steps as $step) {
            $label = (string) ($step['label'] ?? 'step');
            $enabled = (bool) ($step['enabled'] ?? true);
            if (!$enabled) {
                $results[] = ['label' => $label, 'status' => 'SKIP'];
                continue;
            }

            try {
                $code = (int) ($step['run'])();
            } catch (\Throwable) {
                $code = 1;
            }

            if ($code === 0) {
                $results[] = ['label' => $label, 'status' => 'OK'];
                continue;
            }

            $results[] = ['label' => $label, 'status' => 'FAIL'];
            $ok = false;
        }

        return [
            'code' => $ok ? 0 : 1,
            'results' => $results,
        ];
    }

    /**
     * @param array<int, array{label: string, status: string}> $results
     */
    public function printReport(array $results): void
    {
        echo "Preflight:\n";
        foreach ($results as $result) {
            $label = (string) ($result['label'] ?? 'step');
            $status = (string) ($result['status'] ?? 'FAIL');
            echo '- ' . $label . ': ' . $status . "\n";
        }
    }
}
