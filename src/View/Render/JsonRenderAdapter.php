<?php

declare(strict_types=1);

namespace Laas\View\Render;

use Laas\Http\Response;
use Laas\View\RenderAdapterInterface;
use Laas\View\ViewModelInterface;

final class JsonRenderAdapter implements RenderAdapterInterface
{
    public function render(string $template, array|ViewModelInterface $data): Response
    {
        return Response::json($this->normalize($data));
    }

    public function renderPartial(string $template, array|ViewModelInterface $data): Response
    {
        return Response::json($this->normalize($data));
    }

    private function normalize(array|ViewModelInterface $data): array
    {
        if ($data instanceof ViewModelInterface) {
            return $data->toArray();
        }

        $out = [];
        foreach ($data as $key => $value) {
            if ($value instanceof ViewModelInterface) {
                $out[$key] = $value->toArray();
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->normalize($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
