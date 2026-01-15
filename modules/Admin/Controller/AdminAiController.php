<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Ai\Dev\DevAutopilot;
use Laas\Ai\Diff\UnifiedDiffRenderer;
use Laas\Ai\Proposal;
use Laas\Ai\ProposalStore;
use Laas\Ai\ProposalValidator;
use Laas\Api\ApiResponse;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use InvalidArgumentException;
use RuntimeException;

final class AdminAiController
{
    private const MAX_PROPOSAL_BYTES = 300000;

    public function __construct(private View $view)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('pages/ai.html', [], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function saveProposal(Request $request): Response
    {
        $tooLarge = false;
        $proposal = $this->readProposal($request, $tooLarge);
        if ($tooLarge) {
            return $this->renderSaveResult([
                'error' => 'Proposal payload too large.',
            ], 413);
        }
        if ($proposal === null) {
            return $this->renderSaveResult([
                'error' => 'Invalid proposal payload.',
            ], 400);
        }

        if (!isset($proposal['id']) || trim((string) $proposal['id']) === '') {
            $proposal['id'] = bin2hex(random_bytes(16));
        }
        if (!isset($proposal['created_at']) || trim((string) $proposal['created_at']) === '') {
            $proposal['created_at'] = gmdate(DATE_ATOM);
        }

        $validator = new ProposalValidator();
        $errors = $validator->validate($proposal);
        if ($errors !== []) {
            return $this->renderSaveResult([
                'error' => 'Proposal validation failed.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $proposalObj = Proposal::fromArray($proposal);
            $store = new ProposalStore();
            $path = $store->save($proposalObj);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->renderSaveResult([
                'error' => $e->getMessage(),
            ], 400);
        }

        $id = (string) $proposal['id'];
        $hint = 'php tools/cli.php ai:proposal:apply ' . $id . ' --yes';

        return $this->renderSaveResult([
            'saved' => true,
            'id' => $id,
            'path' => $path,
            'cli_hint' => $hint,
        ], 200);
    }

    public function devAutopilot(Request $request): Response
    {
        $input = $request->getPost();
        $moduleName = trim((string) ($input['module_name'] ?? ''));
        if ($moduleName === '' || !preg_match('/^[A-Z][A-Za-z0-9]{2,32}$/', $moduleName)) {
            if ($request->isHtmx()) {
                return $this->renderDevAutopilotResult([
                    'error' => 'Invalid module name.',
                ], 422);
            }

            return ApiResponse::error(ErrorCode::INVALID_REQUEST, 'Invalid request', [
                'module_name' => 'invalid',
            ], 422);
        }

        $autopilot = new DevAutopilot();
        $result = $autopilot->run($moduleName, true, true, false, true, false);
        $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];

        $proposalJson = json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $stepsRaw = is_array($plan['steps'] ?? null) ? $plan['steps'] : [];
        $steps = [];
        foreach ($stepsRaw as $step) {
            if (!is_array($step)) {
                continue;
            }
            $args = is_array($step['args'] ?? null) ? $step['args'] : [];
            $argsText = '';
            if ($args !== []) {
                $argsText = implode(' ', array_map('strval', $args));
            }
            $steps[] = [
                'title' => (string) ($step['title'] ?? ''),
                'command' => (string) ($step['command'] ?? ''),
                'args_text' => $argsText,
            ];
        }
        $fileChanges = is_array($proposal['file_changes'] ?? null) ? $proposal['file_changes'] : [];
        $diffBlocks = $fileChanges !== [] ? (new UnifiedDiffRenderer())->render($fileChanges) : [];

        $viewData = [
            'module_name' => $moduleName,
            'module_path' => (string) ($result['module_path'] ?? ''),
            'proposal_id' => (string) ($result['proposal_id'] ?? ''),
            'plan_id' => (string) ($result['plan_id'] ?? ''),
            'plan_steps' => $steps,
            'proposal_json' => $proposalJson,
            'plan_json' => $planJson,
            'diff_blocks' => $diffBlocks,
            'diff_blocks_present' => $diffBlocks !== [],
        ];

        if ($request->isHtmx()) {
            return $this->renderDevAutopilotResult($viewData, 200);
        }

        return ApiResponse::ok($viewData);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readProposal(Request $request, ?bool &$tooLarge = null): ?array
    {
        $tooLarge = false;
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = $request->getBody();
            if (strlen($raw) > self::MAX_PROPOSAL_BYTES) {
                $tooLarge = true;
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $proposal = $decoded['proposal'] ?? $decoded;
            return is_array($proposal) ? $proposal : null;
        }

        $rawProposal = $request->post('proposal_json');
        if (is_string($rawProposal) && $rawProposal !== '') {
            if (strlen($rawProposal) > self::MAX_PROPOSAL_BYTES) {
                $tooLarge = true;
                return null;
            }
            $decoded = json_decode($rawProposal, true);
            return is_array($decoded) ? $decoded : null;
        }

        $proposal = $request->post('proposal');
        return is_array($proposal) ? $proposal : null;
    }

    private function renderSaveResult(array $data, int $status): Response
    {
        return $this->view->render('partials/ai_save_result.html', $data, $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function renderDevAutopilotResult(array $data, int $status): Response
    {
        return $this->view->render('partials/ai_dev_autopilot_result.html', $data, $status, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }
}
