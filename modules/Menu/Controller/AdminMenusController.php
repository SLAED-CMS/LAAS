<?php

declare(strict_types=1);

namespace Laas\Modules\Menu\Controller;

use Laas\Api\ApiCacheInvalidator;
use Laas\Core\Container\Container;
use Laas\Core\Validation\ValidationResult;
use Laas\Core\Validation\Validator;
use Laas\Domain\Menus\MenusReadServiceInterface;
use Laas\Domain\Menus\MenusWriteServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Menu\Service\MenuCacheInvalidator;
use Laas\Support\Audit;
use Laas\Support\UrlValidator;
use Laas\View\SanitizedHtml;
use Laas\View\View;
use Throwable;

final class AdminMenusController
{
    public function __construct(
        private View $view,
        private ?MenusReadServiceInterface $menusReadService = null,
        private ?MenusWriteServiceInterface $menusWriteService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $menu = $this->getMainMenu();
        $items = $this->loadItems($menu);

        return $this->view->render('pages/menus.html', [
            'menu' => $menu,
            'items' => $items,
            'saved_message' => false,
            'errors' => [],
            'form' => $this->emptyForm(),
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function newItemForm(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        return $this->view->render('partials/menu_item_form.html', [
            'form' => $this->emptyForm(),
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    public function editItemForm(Request $request, array $params = []): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $service = $this->readService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $item = $service->findItem($id);
        if ($item === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        return $this->view->render('partials/menu_item_form.html', [
            'form' => $this->mapForm($item),
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    public function saveItem(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $menu = $this->getMainMenu();
        if ($menu === null) {
            return $this->errorResponse($request, 'menu_missing', 500);
        }

        $id = $this->readId($request);
        $label = trim((string) ($request->post('label') ?? ''));
        $url = trim((string) ($request->post('url') ?? ''));
        $contentFormat = $request->post('content_format');
        $enabled = $request->post('enabled');
        $enabledValue = ($enabled === '1' || $enabled === 1 || $enabled === true) ? '1' : '0';
        $isExternal = $request->post('is_external');
        $isExternalValue = ($isExternal === '1' || $isExternal === 1 || $isExternal === true) ? '1' : '0';
        $sortOrderRaw = trim((string) ($request->post('sort_order') ?? ''));
        $sortOrderValue = $sortOrderRaw === '' ? '0' : $sortOrderRaw;

        $validator = new Validator();
        $result = $validator->validate([
            'label' => $label,
            'url' => $url,
            'enabled' => $enabledValue,
            'is_external' => $isExternalValue,
            'sort_order' => $sortOrderValue,
        ], [
            'label' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:255'],
            'enabled' => ['required', 'in:0,1'],
            'is_external' => ['required', 'in:0,1'],
            'sort_order' => ['regex:/^\\d+$/'],
        ], [
            'translator' => $this->view->getTranslator(),
        ]);

        if (!$result->isValid()) {
            $form = $this->formFromInput([
                'id' => $id ?? 0,
                'label' => $label,
                'url' => $url,
                'enabled' => $enabledValue,
                'is_external' => $isExternalValue,
                'sort_order' => $sortOrderValue,
            ]);
            return $this->renderFormResponse($request, $menu, $result, [
                ...$form,
            ], 422, false, null);
        }
        if (!UrlValidator::isSafe($url)) {
            $form = $this->formFromInput([
                'id' => $id ?? 0,
                'label' => $label,
                'url' => $url,
                'enabled' => $enabledValue,
                'is_external' => $isExternalValue,
                'sort_order' => $sortOrderValue,
            ]);
            return $this->renderFormResponse($request, $menu, [
                [
                    'key' => 'admin.menus.error_invalid',
                    'params' => [],
                ],
            ], [
                ...$form,
            ], 422, false, null);
        }

        $payload = [
            'menu_id' => (int) $menu['id'],
            'label' => $label,
            'url' => $url,
            'enabled' => (int) $enabledValue,
            'is_external' => (int) $isExternalValue,
            'sort_order' => (int) $sortOrderValue,
        ];
        if ($contentFormat !== null) {
            $payload['content_format'] = $contentFormat;
        }
        if ($id !== null) {
            $payload['id'] = $id;
        }

        $service = $this->writeService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        if ($id === null) {
            $itemId = $service->createItem($payload);
        } else {
            $itemId = $service->updateItem($id, $payload);
        }
        $action = $id === null ? 'menus.item.create' : 'menus.item.update';
        Audit::log($action, 'menu_item', $itemId, [
            'label' => $label,
            'url' => $url,
            'enabled' => (int) $enabledValue,
            'is_external' => (int) $isExternalValue,
            'sort_order' => (int) $sortOrderValue,
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        (new MenuCacheInvalidator())->invalidate((string) ($menu['name'] ?? ''));
        (new ApiCacheInvalidator())->invalidateMenu((string) ($menu['name'] ?? ''));

        return $this->renderFormResponse(
            $request,
            $menu,
            null,
            $this->emptyForm(),
            200,
            true,
            $itemId
        );
    }

    public function toggleItem(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $menu = $this->getMainMenu();
        if ($menu === null) {
            return $this->errorResponse($request, 'menu_missing', 500);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $item = $readService->findItem($id);
        if ($item === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $enabled = !empty($item['enabled']) ? 1 : 0;
        $nextEnabled = $enabled === 1 ? 0 : 1;
        $writeService->setItemEnabled($id, $nextEnabled);
        Audit::log($nextEnabled === 1 ? 'menus.item.enable' : 'menus.item.disable', 'menu_item', $id, [
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'enabled' => $nextEnabled,
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        (new MenuCacheInvalidator())->invalidate((string) ($menu['name'] ?? ''));
        (new ApiCacheInvalidator())->invalidateMenu((string) ($menu['name'] ?? ''));

        return $this->renderTableResponse($menu, $request, [], true, $id);
    }

    public function deleteItem(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $menu = $this->getMainMenu();
        if ($menu === null) {
            return $this->errorResponse($request, 'menu_missing', 500);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        $item = $readService->findItem($id);
        $writeService->deleteItem($id);
        Audit::log('menus.item.delete', 'menu_item', $id, [
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        (new MenuCacheInvalidator())->invalidate((string) ($menu['name'] ?? ''));
        (new ApiCacheInvalidator())->invalidateMenu((string) ($menu['name'] ?? ''));

        return $this->renderTableResponse($menu, $request, [], true, null);
    }

    private function renderTableResponse(
        ?array $menu,
        Request $request,
        array $messages,
        bool $savedMessage,
        ?int $flashId
    ): Response {
        $items = $this->loadItems($menu, $flashId);
        $success = $savedMessage ? $this->view->translate('admin.menus.saved') : null;

        if ($request->isHtmx()) {
            return $this->view->render('partials/menu_table_response.html', [
                'menu' => $menu,
                'items' => $items,
                'success' => $success,
                'errors' => $messages,
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/menus.html', [
            'menu' => $menu,
            'items' => $items,
            'success' => $success,
            'errors' => $messages,
            'form' => $this->emptyForm(),
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function renderFormResponse(
        Request $request,
        ?array $menu,
        ValidationResult|array|null $errors,
        array $form,
        int $status,
        bool $savedMessage,
        ?int $flashId
    ): Response {
        $messages = $errors !== null ? $this->resolveErrorMessages($errors) : [];
        $success = $savedMessage ? $this->view->translate('admin.menus.saved') : null;
        $items = $this->loadItems($menu, $flashId);

        if ($request->isHtmx()) {
            return $this->view->render('partials/menu_form_response.html', [
                'menu' => $menu,
                'items' => $items,
                'success' => $success,
                'errors' => $messages,
                'form' => $form,
            ], $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/menus.html', [
            'menu' => $menu,
            'items' => $items,
            'success' => $success,
            'errors' => $messages,
            'form' => $form,
        ], $status, [], [
            'theme' => 'admin',
        ]);
    }

    private function loadItems(?array $menu, ?int $flashId = null): array
    {
        if ($menu === null) {
            return [];
        }

        $service = $this->readService();
        if ($service === null) {
            return [];
        }

        $rows = $service->loadItems((int) $menu['id']);

        return array_map(static function (array $item) use ($flashId): array {
            $item['enabled'] = !empty($item['enabled']);
            $item['is_external'] = !empty($item['is_external']);
            $item['flash'] = $flashId !== null && (int) ($item['id'] ?? 0) === $flashId;
            return $item;
        }, $rows);
    }

    private function getMainMenu(): ?array
    {
        $service = $this->readService();
        if ($service === null) {
            return null;
        }

        return $service->findByName('main');
    }

    private function canEdit(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'menus.edit');
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

    private function readId(Request $request): ?int
    {
        $raw = $request->post('id');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function emptyForm(): array
    {
        return [
            'id' => 0,
            'label' => '',
            'url' => '',
            'sort_order' => '0',
            'enabled_checked' => $this->checkedAttr(true),
            'external_checked' => $this->checkedAttr(false),
        ];
    }

    private function mapForm(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'sort_order' => (string) ($item['sort_order'] ?? '0'),
            'enabled_checked' => $this->checkedAttr(!empty($item['enabled'])),
            'external_checked' => $this->checkedAttr(!empty($item['is_external'])),
        ];
    }

    private function formFromInput(array $data): array
    {
        return [
            'id' => (int) ($data['id'] ?? 0),
            'label' => (string) ($data['label'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'sort_order' => (string) ($data['sort_order'] ?? '0'),
            'enabled_checked' => $this->checkedAttr(!empty($data['enabled']) && (string) $data['enabled'] === '1'),
            'external_checked' => $this->checkedAttr(!empty($data['is_external']) && (string) $data['is_external'] === '1'),
        ];
    }

    private function checkedAttr(bool $checked): SanitizedHtml
    {
        return SanitizedHtml::fromSanitized($checked ? 'checked' : '');
    }

    private function forbidden(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.menus');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.menus');
    }

    /** @return array<int, string> */
    private function resolveErrorMessages(ValidationResult|array $errors): array
    {
        $messages = [];

        if ($errors instanceof ValidationResult) {
            foreach ($errors->errors() as $fieldErrors) {
                foreach ($fieldErrors as $error) {
                    $messages[] = $this->view->translate((string) $error['key'], $error['params'] ?? []);
                }
            }

            return $messages;
        }

        foreach ($errors as $error) {
            $messages[] = $this->view->translate((string) ($error['key'] ?? ''), $error['params'] ?? []);
        }

        return $messages;
    }

    private function readService(): ?MenusReadServiceInterface
    {
        if ($this->menusReadService !== null) {
            return $this->menusReadService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MenusReadServiceInterface::class);
                if ($service instanceof MenusReadServiceInterface) {
                    $this->menusReadService = $service;
                    return $this->menusReadService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function writeService(): ?MenusWriteServiceInterface
    {
        if ($this->menusWriteService !== null) {
            return $this->menusWriteService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MenusWriteServiceInterface::class);
                if ($service instanceof MenusWriteServiceInterface) {
                    $this->menusWriteService = $service;
                    return $this->menusWriteService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
