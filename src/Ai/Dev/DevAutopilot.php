<?php
declare(strict_types=1);

namespace Laas\Ai\Dev;

use Laas\Ai\FileChangeApplier;
use Laas\Ai\Plan;
use Laas\Ai\PlanRunner;
use Laas\Ai\PlanStore;
use Laas\Ai\ProposalStore;
use Laas\Ai\ProposalValidator;
use Throwable;

final class DevAutopilot
{
    private string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
    }

    /**
     * @return array{
     *   mode:string,
     *   proposal_id:string,
     *   proposal_valid:int,
     *   proposal_errors:array<int, array{path: string, message: string}>,
     *   applied:int,
     *   errors:int,
     *   plan_id:string,
     *   plan_failed:int,
     *   module_path:string,
     *   proposal?:array<string, mixed>,
     *   plan?:array<string, mixed>
     * }
     */
    public function run(
        string $name,
        bool $sandbox,
        bool $apiEnvelope,
        bool $yes,
        bool $includePayload = false,
        bool $persist = true
    ): array
    {
        $mode = $yes ? 'execute' : 'dry-run';
        $summary = [
            'mode' => $mode,
            'proposal_id' => '',
            'proposal_valid' => 0,
            'proposal_errors' => [],
            'applied' => 0,
            'errors' => 0,
            'plan_id' => '',
            'plan_failed' => 0,
            'module_path' => $this->modulePath($name, $sandbox),
        ];

        try {
            $proposal = (new ModuleScaffolder())->scaffold($name, $apiEnvelope, $sandbox);
        } catch (Throwable $e) {
            $summary['proposal_errors'] = [
                ['path' => 'name', 'message' => $e->getMessage()],
            ];
            $summary['errors'] = 1;
            return $summary;
        }

        $proposalData = $proposal->toArray();
        $summary['proposal_id'] = (string) ($proposalData['id'] ?? '');

        $validator = new ProposalValidator();
        $errors = $validator->validate($proposalData);
        if ($errors !== []) {
            $summary['proposal_errors'] = $errors;
            $summary['errors'] = count($errors);
            return $summary;
        }

        $summary['proposal_valid'] = 1;
        if ($persist) {
            (new ProposalStore($this->rootPath))->save($proposal);
        }
        if ($includePayload) {
            $summary['proposal'] = $proposalData;
        }

        $planId = bin2hex(random_bytes(16));
        $plan = new Plan([
            'id' => $planId,
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'dev.autopilot.checks',
            'summary' => 'Autopilot checks for ' . $name,
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Templates raw check',
                    'command' => 'templates:raw:check',
                    'args' => ['--path=themes'],
                ],
                [
                    'id' => 's2',
                    'title' => 'Policy check',
                    'command' => 'policy:check',
                    'args' => [],
                ],
            ],
            'confidence' => 0.7,
            'risk' => 'low',
        ]);
        if ($persist) {
            (new PlanStore($this->rootPath))->save($plan);
        }
        $summary['plan_id'] = $planId;
        if ($includePayload) {
            $summary['plan'] = $plan->toArray();
        }

        if (!$yes) {
            return $summary;
        }

        $fileChanges = is_array($proposalData['file_changes'] ?? null) ? $proposalData['file_changes'] : [];
        $applier = new FileChangeApplier($this->rootPath);
        $applySummary = $applier->apply($fileChanges, false, true);
        $summary['applied'] = (int) ($applySummary['applied'] ?? 0);
        $summary['errors'] = (int) ($applySummary['errors'] ?? 0);

        $runner = new PlanRunner($this->rootPath);
        $planResult = $runner->run($plan, false, true);
        $summary['plan_failed'] = (int) ($planResult['failed'] ?? 0);

        return $summary;
    }

    private function modulePath(string $name, bool $sandbox): string
    {
        $name = trim($name);
        if ($sandbox) {
            return 'storage/sandbox/modules/' . $name;
        }

        return 'modules/' . $name;
    }
}
