<?php
declare(strict_types=1);

namespace Laas\Ai\Context;

use Laas\Http\Request;

final class AiContextBuilder
{
    public function __construct(private Redactor $redactor = new Redactor())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $prompt = $this->readPrompt($request);
        $prompt = $this->redactor->redact($prompt);

        return [
            'route' => $request->getPath(),
            'user_id' => $this->resolveUserId($request),
            'timestamp' => gmdate(DATE_ATOM),
            'prompt' => $prompt,
            'capabilities' => [
                'sandbox' => true,
            ],
        ];
    }

    private function readPrompt(Request $request): string
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        $data = [];
        if (str_contains($contentType, 'application/json')) {
            $raw = trim($request->getBody());
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        } else {
            $data = $request->getPost();
        }

        $prompt = (string) ($data['prompt'] ?? '');
        $prompt = trim($prompt);
        if (strlen($prompt) > 4000) {
            $prompt = substr($prompt, 0, 4000);
        }

        return $prompt;
    }

    private function resolveUserId(Request $request): ?int
    {
        $apiUser = $request->getAttribute('api.user');
        if (is_array($apiUser)) {
            $id = (int) ($apiUser['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        $session = $request->session();
        if ($session->isStarted()) {
            $raw = $session->get('user_id');
            if (is_int($raw)) {
                return $raw;
            }
            if (is_string($raw) && ctype_digit($raw)) {
                return (int) $raw;
            }
        }

        return null;
    }
}
