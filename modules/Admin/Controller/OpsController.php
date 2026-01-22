<?php

declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Ops\OpsReadServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Throwable;

final class OpsController
{
    public function __construct(
        private View $view,
        private ?OpsReadServiceInterface $opsService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request, 'admin.ops.index');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->serviceUnavailable($request, 'admin.ops.index');
        }

        $snapshot = $service->overview($request->isHttps());

        if ($this->wantsJson($request)) {
            return ContractResponse::ok($snapshot, [
                'route' => 'admin.ops.index',
            ]);
        }

        $viewData = $service->viewData(
            $snapshot,
            fn (string $key): string => $this->view->translate($key)
        );

        if ($request->isHtmx()) {
            return $this->view->render('partials/ops_cards.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/ops.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function refresh(Request $request): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request, 'admin.ops.index');
        }

        $service = $this->service();
        if ($service === null) {
            return $this->serviceUnavailable($request, 'admin.ops.index');
        }

        $snapshot = $service->overview($request->isHttps());
        $viewData = $service->viewData(
            $snapshot,
            fn (string $key): string => $this->view->translate($key)
        );

        return $this->view->render('partials/ops_cards.html', $viewData, 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function canView(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbac();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'ops.view');
    }

    private function currentUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function forbidden(Request $request, string $route): Response
    {
        if ($this->wantsJson($request)) {
            return ContractResponse::error('forbidden', [
                'route' => $route,
            ], 403);
        }

        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], $route);
    }

    private function serviceUnavailable(Request $request, string $route): Response
    {
        if ($this->wantsJson($request)) {
            return ContractResponse::error('service_unavailable', [
                'route' => $route,
            ], 503);
        }

        return ErrorResponse::respondForRequest($request, 'db_unavailable', [], 503, [], $route);
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->wantsJson()) {
            return true;
        }

        return str_ends_with($request->getPath(), '.json');
    }

    private function service(): ?OpsReadServiceInterface
    {
        if ($this->opsService !== null) {
            return $this->opsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(OpsReadServiceInterface::class);
                if ($service instanceof OpsReadServiceInterface) {
                    $this->opsService = $service;
                    return $this->opsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbac(): ?RbacServiceInterface
    {
        if ($this->container === null) {
            return null;
        }

        try {
            $service = $this->container->get(RbacServiceInterface::class);
            return $service instanceof RbacServiceInterface ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }
}
