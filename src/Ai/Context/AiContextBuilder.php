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
        $input = $this->readInput($request);
        $prompt = $this->readPrompt($input);
        $prompt = $this->redactor->redact($prompt);
        $context = $this->readContext($input);

        $payload = [
            'route' => $request->getPath(),
            'user_id' => $this->resolveUserId($request),
            'timestamp' => gmdate(DATE_ATOM),
            'prompt' => $prompt,
            'capabilities' => [
                'sandbox' => true,
            ],
        ];
        if ($context !== []) {
            $payload['context'] = $context;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function readPrompt(array $input): string
    {
        $prompt = (string) ($input['prompt'] ?? '');
        $prompt = trim($prompt);
        if (strlen($prompt) > 4000) {
            $prompt = substr($prompt, 0, 4000);
        }

        return $prompt;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function readContext(array $input): array
    {
        $raw = $input['context'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $context = [];
        $pageId = $raw['page_id'] ?? null;
        if (is_int($pageId) || (is_string($pageId) && $pageId !== '')) {
            $context['page_id'] = is_int($pageId) ? $pageId : (string) $pageId;
        }

        $field = $raw['field'] ?? null;
        if (is_string($field)) {
            $field = trim($field);
            if ($field !== '') {
                $context['field'] = $field;
            }
        }

        $value = $raw['value'] ?? null;
        if (is_string($value)) {
            if (strlen($value) > 20000) {
                $value = substr($value, 0, 20000);
            }
            $context['value'] = $this->redactor->redact($value);
        }

        $url = $raw['url'] ?? null;
        if (is_string($url)) {
            $url = trim($url);
            if ($url !== '') {
                $context['url'] = $this->redactor->redact($url);
            }
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function readInput(Request $request): array
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = trim($request->getBody());
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $request->getPost();
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
