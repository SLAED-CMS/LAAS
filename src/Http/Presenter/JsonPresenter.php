<?php
declare(strict_types=1);

namespace Laas\Http\Presenter;

use Laas\Http\Response;

final class JsonPresenter implements PresenterInterface
{
    public function present(array $data, array $meta = []): Response
    {
        $meta['format'] = 'json';
        return Response::json([
            'data' => $data,
            'meta' => $meta,
        ], $this->resolveStatus($meta));
    }

    private function resolveStatus(array $meta): int
    {
        if (isset($meta['status']) && is_int($meta['status'])) {
            return $meta['status'];
        }

        if (isset($meta['code']) && is_int($meta['code'])) {
            return $meta['code'];
        }

        return 200;
    }
}
