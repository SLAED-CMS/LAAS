<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Ai\Proposal;
use Laas\Ai\ProposalStore;
use Laas\Ai\ProposalValidator;
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
            return Response::html('<div class="alert alert-danger">Proposal payload too large.</div>', 413);
        }
        if ($proposal === null) {
            return Response::html('<div class="alert alert-danger">Invalid proposal payload.</div>', 400);
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
            $items = array_map(static function (array $error): string {
                $path = htmlspecialchars((string) ($error['path'] ?? ''), ENT_QUOTES);
                $message = htmlspecialchars((string) ($error['message'] ?? ''), ENT_QUOTES);
                return '<li><code>' . $path . '</code>: ' . $message . '</li>';
            }, $errors);
            return Response::html(
                '<div class="alert alert-danger">Proposal validation failed.</div>'
                . '<ul class="small mb-0">' . implode('', $items) . '</ul>',
                422
            );
        }

        try {
            $proposalObj = Proposal::fromArray($proposal);
            $store = new ProposalStore();
            $path = $store->save($proposalObj);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $message = htmlspecialchars($e->getMessage(), ENT_QUOTES);
            return Response::html('<div class="alert alert-danger">' . $message . '</div>', 400);
        }

        $id = htmlspecialchars((string) $proposal['id'], ENT_QUOTES);
        $pathEsc = htmlspecialchars($path, ENT_QUOTES);
        $hint = htmlspecialchars('php tools/cli.php ai:proposal:apply ' . $id . ' --yes', ENT_QUOTES);

        return Response::html(
            '<div class="alert alert-success mb-2">Saved proposal id=' . $id . ' path=' . $pathEsc . '</div>'
            . '<div class="small text-muted">CLI: ' . $hint . '</div>',
            200
        );
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
}
