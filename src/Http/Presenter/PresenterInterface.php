<?php
declare(strict_types=1);

namespace Laas\Http\Presenter;

use Laas\Http\Response;

interface PresenterInterface
{
    public function present(array $data, array $meta = []): Response;
}
