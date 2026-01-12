<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\Http\Presenter\HtmlPresenter;
use Laas\Http\Presenter\JsonPresenter;
use Laas\View\View;

final class Responder
{
    private FormatResolver $resolver;

    public function __construct(
        private View $view,
        ?FormatResolver $resolver = null
    ) {
        $this->resolver = $resolver ?? new FormatResolver();
    }

    public function respond(Request $req, string $template, array $data, array $json = []): Response
    {
        $format = $this->resolver->resolve($req);
        if ($format === 'html' && $this->shouldForceJson($req)) {
            $format = 'json';
        }

        if ($format === 'json') {
            [$payload, $meta] = $this->normalizeJsonPayload($data, $json);
            return (new JsonPresenter())->present($payload, $meta);
        }

        return (new HtmlPresenter($this->view, $template))->present($data);
    }

    private function shouldForceJson(Request $req): bool
    {
        if (!$req->isHeadless()) {
            return false;
        }

        $format = strtolower((string) ($req->query('format') ?? ''));
        return $format !== 'html';
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function normalizeJsonPayload(array $data, array $json): array
    {
        if ($json === []) {
            return [$data, []];
        }

        if (array_key_exists('data', $json) || array_key_exists('meta', $json)) {
            $payload = is_array($json['data'] ?? null) ? $json['data'] : [];
            $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];
            return [$payload, $meta];
        }

        return [$json, []];
    }
}
