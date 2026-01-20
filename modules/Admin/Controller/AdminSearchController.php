<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\AdminSearch\AdminSearchServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;
use Throwable;

final class AdminSearchController
{
    public function __construct(
        private View $view,
        private ?AdminSearchServiceInterface $searchService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        $service = $this->service();
        if ($service === null) {
            return $this->view->render('search/index.html', [
                'errors' => [$this->view->translate('error.service_unavailable')],
                'q' => '',
                'admin_search_results' => true,
                'search' => $service !== null ? $service->search('') : $this->emptySearch(),
            ], 503, [], [
                'theme' => 'admin',
            ]);
        }

        $query = (string) ($request->query('q') ?? '');
        $options = $this->buildOptions($request);
        $search = $service->search($query, $options);
        $status = $search['reason'] === 'too_short' ? 422 : 200;

        $data = [
            'q' => $search['q'] ?? '',
            'errors' => $search['reason'] === 'too_short' ? [$this->view->translate('search.too_short')] : [],
            'admin_search_results' => true,
            'search' => $search,
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/admin_search_results.html', $data, $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('search/index.html', $data, $status, [], [
            'theme' => 'admin',
        ]);
    }

    /** @return array<string, mixed> */
    private function buildOptions(Request $request): array
    {
        $userId = $this->currentUserId($request);
        $rbac = $this->rbacService();

        if ($userId === null || $rbac === null) {
            return [
                'can_pages' => false,
                'can_media' => false,
                'can_users' => false,
                'can_menus' => false,
                'can_modules' => false,
                'can_security_reports' => false,
                'can_ops' => false,
            ];
        }

        return [
            'can_pages' => $this->canAny($rbac, $userId, ['pages.edit', 'pages.view']),
            'can_media' => $this->canAny($rbac, $userId, ['media.view']),
            'can_users' => $this->canAny($rbac, $userId, ['users.manage', 'users.view']),
            'can_menus' => $this->canAny($rbac, $userId, ['menus.edit']),
            'can_modules' => $this->canAny($rbac, $userId, ['admin.modules.manage']),
            'can_security_reports' => $this->canAny($rbac, $userId, ['security_reports.view']),
            'can_ops' => $this->canAny($rbac, $userId, ['ops.view']),
        ];
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

    private function canAny(RbacServiceInterface $rbac, int $userId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($rbac->userHasPermission($userId, $permission)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private function service(): ?AdminSearchServiceInterface
    {
        if ($this->searchService !== null) {
            return $this->searchService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(AdminSearchServiceInterface::class);
                if ($service instanceof AdminSearchServiceInterface) {
                    $this->searchService = $service;
                    return $this->searchService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
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

    /** @return array<string, mixed> */
    private function emptySearch(): array
    {
        return [
            'q' => '',
            'total' => 0,
            'groups' => [],
            'reason' => null,
        ];
    }
}
