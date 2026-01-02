<?php
declare(strict_types=1);

namespace Laas\Modules\Menu\Controller;

use Laas\Core\Validation\Validator;
use Laas\Core\Validation\ValidationResult;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Modules\Menu\Repository\MenuItemsRepository;
use Laas\Modules\Menu\Repository\MenusRepository;
use Laas\View\View;
use Throwable;

final class AdminMenusController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canEdit()) {
            return $this->forbidden();
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
        if (!$this->canEdit()) {
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
        if (!$this->canEdit()) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $repo = $this->itemsRepo();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $item = $repo->findById($id);
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
        if (!$this->canEdit()) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $menu = $this->getMainMenu();
        if ($menu === null) {
            return $this->errorResponse($request, 'menu_missing', 500);
        }

        $id = $this->readId($request);
        $label = trim((string) ($request->post('label') ?? ''));
        $url = trim((string) ($request->post('url') ?? ''));
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

        $payload = [
            'menu_id' => (int) $menu['id'],
            'label' => $label,
            'url' => $url,
            'enabled' => (int) $enabledValue,
            'is_external' => (int) $isExternalValue,
            'sort_order' => (int) $sortOrderValue,
        ];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        $repo = $this->itemsRepo();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        $itemId = $repo->saveItem($payload);
        $action = $id === null ? 'menus.item.create' : 'menus.item.update';
        (new AuditLogger($this->db))->log(
            $action,
            'menu_item',
            $itemId,
            [
                'label' => $label,
                'url' => $url,
                'enabled' => (int) $enabledValue,
                'is_external' => (int) $isExternalValue,
                'sort_order' => (int) $sortOrderValue,
            ],
            $this->currentUserId(),
            $request->ip()
        );

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
        if (!$this->canEdit()) {
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

        $repo = $this->itemsRepo();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $item = $repo->findById($id);
        if ($item === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $enabled = !empty($item['enabled']) ? 1 : 0;
        $nextEnabled = $enabled === 1 ? 0 : 1;
        $repo->setEnabled($id, $nextEnabled);
        (new AuditLogger($this->db))->log(
            $nextEnabled === 1 ? 'menus.item.enable' : 'menus.item.disable',
            'menu_item',
            $id,
            [
                'label' => (string) ($item['label'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'enabled' => $nextEnabled,
            ],
            $this->currentUserId(),
            $request->ip()
        );

        return $this->renderTableResponse($menu, $request, [], true, $id);
    }

    public function deleteItem(Request $request): Response
    {
        if (!$this->canEdit()) {
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

        $repo = $this->itemsRepo();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        $item = $repo->findById($id);
        $repo->deleteItem($id);
        (new AuditLogger($this->db))->log(
            'menus.item.delete',
            'menu_item',
            $id,
            [
                'label' => (string) ($item['label'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
            ],
            $this->currentUserId(),
            $request->ip()
        );

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

        if ($request->isHtmx()) {
            return $this->view->render('partials/menu_table_response.html', [
                'menu' => $menu,
                'items' => $items,
                'saved_message' => $savedMessage,
                'errors' => $messages,
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/menus.html', [
            'menu' => $menu,
            'items' => $items,
            'saved_message' => $savedMessage,
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
        $items = $this->loadItems($menu, $flashId);

        if ($request->isHtmx()) {
            return $this->view->render('partials/menu_form_response.html', [
                'menu' => $menu,
                'items' => $items,
                'saved_message' => $savedMessage,
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
            'saved_message' => $savedMessage,
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

        $repo = $this->itemsRepo();
        if ($repo === null) {
            return [];
        }

        $rows = $repo->listItems((int) $menu['id']);

        return array_map(static function (array $item) use ($flashId): array {
            $item['enabled'] = !empty($item['enabled']);
            $item['is_external'] = !empty($item['is_external']);
            $item['flash'] = $flashId !== null && (int) ($item['id'] ?? 0) === $flashId;
            return $item;
        }, $rows);
    }

    private function getMainMenu(): ?array
    {
        $repo = $this->menusRepo();
        if ($repo === null) {
            return null;
        }

        return $repo->findMenuByName('main');
    }

    private function menusRepo(): ?MenusRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MenusRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function itemsRepo(): ?MenuItemsRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MenuItemsRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function canEdit(): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'menus.edit');
        } catch (Throwable) {
            return false;
        }
    }

    private function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $raw = $_SESSION['user_id'] ?? null;
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
            'enabled_checked' => 'checked',
            'external_checked' => '',
        ];
    }

    private function mapForm(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'sort_order' => (string) ($item['sort_order'] ?? '0'),
            'enabled_checked' => !empty($item['enabled']) ? 'checked' : '',
            'external_checked' => !empty($item['is_external']) ? 'checked' : '',
        ];
    }

    private function formFromInput(array $data): array
    {
        return [
            'id' => (int) ($data['id'] ?? 0),
            'label' => (string) ($data['label'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'sort_order' => (string) ($data['sort_order'] ?? '0'),
            'enabled_checked' => !empty($data['enabled']) && (string) $data['enabled'] === '1' ? 'checked' : '',
            'external_checked' => !empty($data['is_external']) && (string) $data['is_external'] === '1' ? 'checked' : '',
        ];
    }

    private function forbidden(): Response
    {
        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
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
}
