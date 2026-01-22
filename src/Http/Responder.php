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

        if ($format === 'json') {
            [$payload, $meta] = $this->normalizeJsonPayload($data, $json);
            return (new JsonPresenter())->present($payload, $meta);
        }

        return (new HtmlPresenter($this->view, $template))->present($data);
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function normalizeJsonPayload(array $data, array $json): array
    {
        if ($json === []) {
            return [$data, []];
        }

        if (array_key_exists('data', $json)) {
            $payload = is_array($json['data'] ?? null) ? $json['data'] : [];
            $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];
            return [$payload, $meta];
        }

        return [$json, []];
    }
}
