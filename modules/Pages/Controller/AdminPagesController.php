<?php

declare(strict_types=1);

namespace Laas\Modules\Pages\Controller;

use Laas\Admin\Editors\EditorProvidersRegistry;
use Laas\Api\ApiCacheInvalidator;
use Laas\Assets\AssetsManager;
use Laas\Content\Blocks\BlockRegistry;
use Laas\Content\Blocks\BlockValidationException;
use Laas\Content\Blocks\ThemeContext;
use Laas\Core\Container\Container;
use Laas\Core\Validation\Rules;
use Laas\Core\Validation\ValidationResult;
use Laas\Core\Validation\Validator;
use Laas\Domain\Pages\PagesReadServiceInterface;
use Laas\Domain\Pages\PagesWriteServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Pages\ViewModel\PagePublicViewModel;
use Laas\Security\HtmlSanitizer;
use Laas\Support\Audit;
use Laas\Support\RequestScope;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
use Laas\Ui\UiTokenMapper;
use Laas\View\SanitizedHtml;
use Laas\View\View;
use Throwable;

final class AdminPagesController
{
    private const RESERVED = [
        'admin',
        'api',
        'login',
        'logout',
        'csrf',
        'echo',
        'assets',
        'themes',
        'media',
    ];

    public function __construct(
        private View $view,
        private ?PagesReadServiceInterface $pagesReadService = null,
        private ?PagesWriteServiceInterface $pagesWriteService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $service = $this->readService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        $status = (string) ($request->query('status') ?? 'all');
        if (!in_array($status, ['all', 'draft', 'published'], true)) {
            $status = 'all';
        }

        if (SearchNormalizer::isTooShort($query)) {
            $message = $this->view->translate('search.too_short');
            if ($request->isHtmx()) {
                $response = $this->view->render('partials/messages.html', [
                    'errors' => [$message],
                ], 422, [], [
                    'theme' => 'admin',
                    'render_partial' => true,
                ]);
                return $response->withHeader('HX-Retarget', '#page-messages');
            }

            return $this->view->render('pages/pages.html', [
                'pages' => [],
                'q' => $query,
                'errors' => [$message],
                'status_selected_all' => $this->selectedAttr($status === 'all'),
                'status_selected_draft' => $this->selectedAttr($status === 'draft'),
                'status_selected_published' => $this->selectedAttr($status === 'published'),
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $rows = [];
        $canEdit = true;
        try {
            if ($query !== '') {
                $search = new SearchQuery($query, 50, 1, 'pages');
                $pages = $service->list([
                    'query' => $search->q,
                    'status' => $status,
                    'limit' => $search->limit,
                    'offset' => $search->offset,
                ]);
                foreach ($pages as $page) {
                    $rows[] = $this->buildPageRow($page, $canEdit, $search->q);
                }
            } else {
                $pages = $service->list([
                    'status' => $status,
                    'limit' => 100,
                    'offset' => 0,
                ]);
                foreach ($pages as $page) {
                    $rows[] = $this->buildPageRow($page, $canEdit);
                }
            }
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $viewData = [
            'pages' => $rows,
            'q' => $query,
            'status_selected_all' => $this->selectedAttr($status === 'all'),
            'status_selected_draft' => $this->selectedAttr($status === 'draft'),
            'status_selected_published' => $this->selectedAttr($status === 'published'),
        ];

        if ($request->isHtmx()) {
            return $this->view->render('partials/pages_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/pages.html', [
            ...$viewData,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function createForm(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $editorContext = $this->editorContext();
        $contentFormat = $this->normalizeContentFormat('html');
        $selection = $this->resolveEditorSelection($contentFormat, $editorContext['caps']);
        return $this->view->render('pages/page_form.html', [
            'mode' => 'create',
            'is_edit' => false,
            'page' => $this->emptyPage(),
            'status_selected_draft' => $this->selectedAttr(true),
            'status_selected_published' => $this->selectedAttr(false),
            'legacy_content' => false,
            'blocks_json_allowed' => $this->blocksJsonAllowed($request),
            'blocks_registry_types' => $this->blocksRegistry()->types(),
            'editor_selection_source' => 'default',
            'editor_selected_id' => $selection['id'],
            'editor_selected_format' => $selection['format'],
            'editors' => $this->markEditorSelection($editorContext['editors'], $selection['id']),
            'editor_caps' => $editorContext['caps'],
            'editor_assets' => $editorContext['assets'],
            'editor_configs' => $editorContext['configs'],
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function editForm(Request $request, array $params = []): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->notFound();
        }

        $service = $this->readService();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        try {
            $page = $service->find($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        if ($page === null) {
            return $this->notFound();
        }

        $blocksJsonAllowed = $this->blocksJsonAllowed($request);
        $blocksJson = $this->loadBlocksJson($id);
        $blocks = $this->decodeBlocksJson($blocksJson);
        $page['blocks_json'] = $blocksJsonAllowed ? $blocksJson : '';
        $legacyDetected = $this->isLegacyContent($page, $blocks);
        $legacyAllowed = $legacyDetected && $this->compatBlocksLegacyContent();
        $status = (string) ($page['status'] ?? 'draft');
        $editorContext = $this->editorContext();
        $contentFormat = $this->normalizeContentFormat($page['content_format'] ?? 'html');
        $selection = $this->resolveEditorSelection($contentFormat, $editorContext['caps']);
        return $this->view->render('pages/page_form.html', [
            'mode' => 'edit',
            'is_edit' => true,
            'page' => $page,
            'status_selected_draft' => $this->selectedAttr($status === 'draft'),
            'status_selected_published' => $this->selectedAttr($status === 'published'),
            'legacy_content' => $legacyAllowed,
            'blocks_json_allowed' => $blocksJsonAllowed,
            'blocks_registry_types' => $this->blocksRegistry()->types(),
            'editor_selection_source' => $this->selectionSource($page),
            'editor_selected_id' => $selection['id'],
            'editor_selected_format' => $selection['format'],
            'editors' => $this->markEditorSelection($editorContext['editors'], $selection['id']),
            'editor_caps' => $editorContext['caps'],
            'editor_assets' => $editorContext['assets'],
            'editor_configs' => $editorContext['configs'],
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        $title = trim((string) ($request->post('title') ?? ''));
        $slug = trim((string) ($request->post('slug') ?? ''));
        $content = (string) ($request->post('content') ?? '');
        $contentFormat = $this->normalizeContentFormat($request->post('content_format'));
        $status = (string) ($request->post('status') ?? 'draft');
        $blocksRaw = '';
        $blocksData = null;
        if ($this->blocksJsonAllowed($request)) {
            $blocksRaw = trim((string) ($request->post('blocks_json') ?? ''));
            if ($blocksRaw === '') {
                $blocksRaw = '[]';
            }
            $decoded = json_decode($blocksRaw, true);
            if (!is_array($decoded)) {
                return $this->blocksJsonErrorResponse($request, [
                    'id' => $id ?? 0,
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'content_format' => $contentFormat,
                    'status' => $status,
                    'blocks_json' => $blocksRaw,
                ], $this->blocksJsonDecodeDetail());
            }

            try {
                $blocksData = $this->blocksRegistry()->normalizeBlocks($decoded);
            } catch (BlockValidationException $error) {
                return $this->blocksJsonErrorResponse($request, [
                    'id' => $id ?? 0,
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'content_format' => $contentFormat,
                    'status' => $status,
                    'blocks_json' => $blocksRaw,
                ], $this->blocksJsonValidationDetail($error));
            }
        }

        $data = [
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'content_format' => $contentFormat,
            'status' => $status,
        ];

        $validator = new Validator();
        $reservedRule = 'reserved_slug:' . implode(',', self::RESERVED);
        $result = $validator->validate($data, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'slug', 'max:255', $reservedRule],
            'status' => ['required', 'in:draft,published'],
            'content' => ['string'],
        ], [
            'label_prefix' => 'pages',
            'translator' => $this->view->getTranslator(),
        ]);

        $this->applyUniqueSlugCheck($result, $slug, $id, $readService);

        if (!$result->isValid()) {
            return $this->formErrorResponse($request, $result, [
                'id' => $id ?? 0,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'content_format' => $contentFormat,
                'status' => $status,
                'blocks_json' => $blocksRaw,
            ]);
        }

        try {
            if ($id === null) {
                $created = $writeService->create([
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'content_format' => $contentFormat,
                    'status' => $status,
                ]);
                $newId = (int) ($created['id'] ?? 0);
                if ($blocksData !== null) {
                    $writeService->createRevision($newId, $blocksData, $this->currentUserId($request));
                }
                Audit::log('pages.create', 'page', $newId, [
                    'title' => $title,
                    'slug' => $slug,
                    'status' => $status,
                    'actor_user_id' => $this->currentUserId($request),
                    'actor_ip' => $request->ip(),
                ]);
            } else {
                $writeService->update($id, [
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'content_format' => $contentFormat,
                    'status' => $status,
                ]);
                if ($blocksData !== null) {
                    $writeService->createRevision($id, $blocksData, $this->currentUserId($request));
                }
                Audit::log('pages.update', 'page', $id, [
                    'title' => $title,
                    'slug' => $slug,
                    'status' => $status,
                    'actor_user_id' => $this->currentUserId($request),
                    'actor_ip' => $request->ip(),
                ]);
            }
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        (new ApiCacheInvalidator())->bumpPages();

        if ($request->isHtmx()) {
            $response = $this->view->render('partials/form_errors.html', [
                'errors' => [],
            ], 200, [], [
                'theme' => 'admin',
            ]);
            return $this->withSuccessTrigger($response, 'admin.pages.saved');
        }

        return new Response('', 303, [
            'Location' => '/admin/pages',
        ]);
    }

    public function previewBlocks(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        if (!$this->blocksJsonAllowed($request)) {
            return $this->forbidden($request);
        }

        $title = trim((string) ($request->post('title') ?? ''));
        $slug = trim((string) ($request->post('slug') ?? ''));
        $content = (string) ($request->post('content') ?? '');
        $blocksRaw = trim((string) ($request->post('blocks_json') ?? ''));
        if ($blocksRaw === '') {
            $blocksRaw = '[]';
        }

        $decoded = json_decode($blocksRaw, true);
        if (!is_array($decoded)) {
            return $this->blocksJsonErrorResponse($request, [
                'id' => 0,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'status' => (string) ($request->post('status') ?? 'draft'),
                'blocks_json' => $blocksRaw,
            ], $this->blocksJsonDecodeDetail());
        }

        try {
            $blocksData = $this->blocksRegistry()->normalizeBlocks($decoded);
        } catch (BlockValidationException $error) {
            return $this->blocksJsonErrorResponse($request, [
                'id' => 0,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'status' => (string) ($request->post('status') ?? 'draft'),
                'blocks_json' => $blocksRaw,
            ], $this->blocksJsonValidationDetail($error));
        }

        $sanitizedContent = (new HtmlSanitizer())->sanitize($content);
        $page = [
            'slug' => $slug,
            'title' => $title,
            'content' => $sanitizedContent,
        ];
        $vm = PagePublicViewModel::fromArray($page);
        $blocksHtml = $this->blocksRegistry()->renderHtmlBlocks($blocksData, new ThemeContext(
            $this->view->getThemeName(),
            $this->view->getLocale()
        ));
        $blocksJson = $this->blocksRegistry()->renderJsonBlocks($blocksData);
        $legacyDetected = $this->isLegacyContent($page, $blocksData);
        $legacyAllowed = $legacyDetected && $this->compatBlocksLegacyContent();
        $viewData = $vm->toArray();
        $viewData['blocks_html'] = $blocksHtml;
        $viewData['blocks_json'] = $blocksJson;
        $viewData['legacy_content_allowed'] = $legacyAllowed;
        $viewData['legacy_content_detected'] = $legacyDetected;

        return $this->view->render('pages/page.html', $viewData, 200, $this->previewHeaders());
    }

    public function delete(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->forbidden($request);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->notFound($request);
        }

        $readService = $this->readService();
        $writeService = $this->writeService();
        if ($readService === null || $writeService === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        try {
            $page = $readService->find($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        if ($page === null) {
            return $this->notFound();
        }

        try {
            $writeService->deleteRevisionsByPageId($id);
            $writeService->delete($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        Audit::log('pages.delete', 'page', $id, [
            'title' => (string) ($page['title'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'actor_user_id' => $this->currentUserId($request),
            'actor_ip' => $request->ip(),
        ]);

        (new ApiCacheInvalidator())->bumpPages();

        if ($request->isHtmx()) {
            return new Response('', 200);
        }

        return new Response('', 302, [
            'Location' => '/admin/pages',
        ]);
    }

    public function toggleStatus(Request $request): Response
    {
        if (!$this->canEdit($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
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

        try {
            $page = $readService->find($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        if ($page === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $status = (string) ($page['status'] ?? 'draft');
        $nextStatus = $status === 'published' ? 'draft' : 'published';
        try {
            $writeService->updateStatus($id, $nextStatus);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $page['status'] = $nextStatus;
        $row = $this->buildPageRow($page, true);
        $row['flash'] = true;

        (new ApiCacheInvalidator())->bumpPages();

        if ($request->isHtmx()) {
            $response = $this->view->render('partials/page_row.html', [
                'page' => $row,
            ], 200, [], [
                'theme' => 'admin',
            ]);
            $messageKey = $nextStatus === 'published' ? 'admin.pages.status.published' : 'admin.pages.status.draft';
            return $this->withSuccessTrigger($response, $messageKey);
        }

        return new Response('', 302, [
            'Location' => '/admin/pages',
        ]);
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

        return $rbac->userHasPermission($userId, 'pages.edit');
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

    private function emptyPage(): array
    {
        return [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'content' => '',
            'status' => 'draft',
            'blocks_json' => '[]',
        ];
    }

    private function loadBlocksJson(int $pageId): string
    {
        $service = $this->readService();
        if ($service === null) {
            return '[]';
        }
        try {
            $row = $service->findLatestRevision($pageId);
        } catch (Throwable) {
            return '[]';
        }
        if ($row === null) {
            return '[]';
        }
        return $this->formatBlocksJson((string) ($row['blocks_json'] ?? ''));
    }

    private function readService(): ?PagesReadServiceInterface
    {
        if ($this->pagesReadService !== null) {
            return $this->pagesReadService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(PagesReadServiceInterface::class);
                if ($service instanceof PagesReadServiceInterface) {
                    $this->pagesReadService = $service;
                    return $this->pagesReadService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function writeService(): ?PagesWriteServiceInterface
    {
        if ($this->pagesWriteService !== null) {
            return $this->pagesWriteService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(PagesWriteServiceInterface::class);
                if ($service instanceof PagesWriteServiceInterface) {
                    $this->pagesWriteService = $service;
                    return $this->pagesWriteService;
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

    private function blocksRegistry(): BlockRegistry
    {
        $registry = RequestScope::get('blocks.registry');
        if ($registry instanceof BlockRegistry) {
            return $registry;
        }
        return BlockRegistry::default();
    }

    /**
     * @param array<string, mixed> $page
     */
    private function isLegacyContent(array $page, ?array $blocks = null): bool
    {
        if ($blocks === null) {
            $blocks = $this->decodeBlocksJson((string) ($page['blocks_json'] ?? ''));
        }
        if ($blocks !== []) {
            return false;
        }
        $content = (string) ($page['content'] ?? '');
        return trim($content) !== '';
    }

    private function compatBlocksLegacyContent(): bool
    {
        $config = $this->compatConfig();
        return (bool) ($config['compat_blocks_legacy_content'] ?? false);
    }

    private function compatConfig(): array
    {
        $path = $this->rootPath() . '/config/compat.php';
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function blocksJsonAllowed(Request $request): bool
    {
        if ($this->isAppDebug()) {
            return true;
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasRole($userId, 'admin');
    }

    private function isAppDebug(): bool
    {
        $config = $this->appConfig();
        return (bool) ($config['debug'] ?? false);
    }

    private function appConfig(): array
    {
        $path = $this->rootPath() . '/config/app.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetsConfig(): array
    {
        $path = $this->rootPath() . '/config/assets.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /**
     * @return array{editors: array<int, array{id: string, label: string, format: string, available: bool, reason: string}>, caps: array<string, array{available: bool, reason: string}>, assets: array<string, array{js: string, css: string}|string>, configs: array<string, string>}
     */
    private function editorContext(): array
    {
        $registry = $this->editorRegistry();
        return [
            'editors' => $registry->editors(),
            'caps' => $registry->capabilities(),
            'assets' => $registry->assets(),
            'configs' => $this->encodeEditorConfigs($registry->configs()),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     * @return array<string, string>
     */
    private function encodeEditorConfigs(array $configs): array
    {
        $out = [];
        foreach ($configs as $id => $config) {
            $encoded = json_encode($config, JSON_UNESCAPED_SLASHES);
            $out[$id] = is_string($encoded) ? $encoded : '{}';
        }
        return $out;
    }

    private function editorRegistry(): EditorProvidersRegistry
    {
        if ($this->container !== null) {
            try {
                $registry = $this->container->get(EditorProvidersRegistry::class);
                if ($registry instanceof EditorProvidersRegistry) {
                    return $registry;
                }
            } catch (Throwable) {
                // Fall through to local registry.
            }
        }

        return new EditorProvidersRegistry(new AssetsManager($this->assetsConfig()));
    }

    /**
     * @param array<string, array{available: bool, reason: string}> $caps
     * @return array{id: string, format: string}
     */
    private function resolveEditorSelection(string $format, array $caps): array
    {
        $format = $this->normalizeContentFormat($format);
        if ($format === 'markdown') {
            if (($caps['toastui']['available'] ?? false)) {
                return ['id' => 'toastui', 'format' => 'markdown'];
            }
            return ['id' => 'textarea', 'format' => 'html'];
        }

        if (($caps['tinymce']['available'] ?? false)) {
            return ['id' => 'tinymce', 'format' => 'html'];
        }

        return ['id' => 'textarea', 'format' => 'html'];
    }

    /**
     * @param array<string, mixed> $page
     */
    private function selectionSource(array $page): string
    {
        $raw = (string) ($page['content_format'] ?? '');
        return $raw === '' ? 'default' : 'content';
    }

    /**
     * @param array<int, array{id: string, label: string, format: string, available: bool, reason: string}> $editors
     * @return array<int, array{id: string, label: string, format: string, available: bool, reason: string, selected: bool}>
     */
    private function markEditorSelection(array $editors, string $selectedId): array
    {
        foreach ($editors as $index => $editor) {
            $editors[$index]['selected'] = $editor['id'] === $selectedId;
        }
        return $editors;
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function decodeBlocksJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function formatBlocksJson(string $raw): string
    {
        $decoded = $this->decodeBlocksJson($raw);
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($pretty)) {
            return '[]';
        }
        return $pretty;
    }

    /** @return array<string, string> */
    private function previewHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
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

    private function formErrorResponse(Request $request, ValidationResult|array $errors, array $page): Response
    {
        $messages = $this->resolveErrorMessages($errors);

        if ($request->isHtmx()) {
            $response = $this->view->render('partials/form_errors.html', [
                'errors' => $messages,
            ], 422, [], [
                'theme' => 'admin',
            ]);
            return $response->withToastDanger('toast.validation_failed', $this->view->translate('toast.validation_failed'), 8000);
        }

        if ($request->wantsJson()) {
            $fields = [];
            if ($errors instanceof ValidationResult) {
                $fields = $errors->toErrorMap();
            } else {
                $result = new ValidationResult();
                foreach ($errors as $error) {
                    $field = (string) ($error['field'] ?? 'form');
                    $key = (string) ($error['key'] ?? '');
                    if ($key !== '') {
                        $result->addError($field, $key, $error['params'] ?? []);
                    }
                }
                $fields = $result->toErrorMap();
            }

            return ErrorResponse::respond($request, ErrorCode::VALIDATION_FAILED, ['fields' => $fields], 422, [], 'validation.pages.save');
        }

        $isEdit = !empty($page['id']);
        $status = (string) ($page['status'] ?? 'draft');
        $legacyDetected = $this->isLegacyContent($page);
        $legacyAllowed = $legacyDetected && $this->compatBlocksLegacyContent();
        $editorContext = $this->editorContext();
        $contentFormat = $this->normalizeContentFormat($page['content_format'] ?? 'html');
        $selection = $this->resolveEditorSelection($contentFormat, $editorContext['caps']);
        return $this->view->render('pages/page_form.html', [
            'mode' => $isEdit ? 'edit' : 'create',
            'is_edit' => $isEdit,
            'page' => $page,
            'status_selected_draft' => $this->selectedAttr($status === 'draft'),
            'status_selected_published' => $this->selectedAttr($status === 'published'),
            'errors' => $messages,
            'legacy_content' => $legacyAllowed,
            'blocks_json_allowed' => $this->blocksJsonAllowed($request),
            'blocks_registry_types' => $this->blocksRegistry()->types(),
            'editor_selection_source' => $this->selectionSource($page),
            'editor_selected_id' => $selection['id'],
            'editor_selected_format' => $selection['format'],
            'editors' => $this->markEditorSelection($editorContext['editors'], $selection['id']),
            'editor_caps' => $editorContext['caps'],
            'editor_assets' => $editorContext['assets'],
            'editor_configs' => $editorContext['configs'],
        ], 422, [], [
            'theme' => 'admin',
        ]);
    }

    private function applyUniqueSlugCheck(
        ValidationResult $result,
        string $slug,
        ?int $ignoreId,
        PagesReadServiceInterface $service
    ): void {
        if (!$result->isValid()) {
            return;
        }

        $slug = trim($slug);
        if ($slug === '') {
            return;
        }

        try {
            $matches = $service->list([
                'slug' => $slug,
                'status' => 'all',
                'limit' => 1,
                'offset' => 0,
            ]);
        } catch (Throwable) {
            $result->addError('slug', 'validation.unique', [
                'field' => $this->fieldLabel('slug'),
            ]);
            return;
        }

        $existing = $matches[0] ?? null;
        if (!is_array($existing)) {
            return;
        }

        $existingId = (int) ($existing['id'] ?? 0);
        if ($existingId <= 0) {
            return;
        }

        if ($ignoreId === null || $existingId !== $ignoreId) {
            $result->addError('slug', 'validation.unique', [
                'field' => $this->fieldLabel('slug'),
            ]);
        }
    }

    private function fieldLabel(string $field): string
    {
        $key = Rules::fieldLabelKeys()['pages.' . $field] ?? '';
        if ($key !== '') {
            $translator = $this->view->getTranslator();
            if (is_object($translator) && method_exists($translator, 'trans')) {
                $label = (string) $translator->trans($key);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return ucfirst($field);
    }

    private function normalizeContentFormat(mixed $format): string
    {
        $format = strtolower(trim((string) $format));
        return in_array($format, ['markdown', 'html'], true) ? $format : 'html';
    }

    private function blocksJsonErrorResponse(Request $request, array $page, string $detail): Response
    {
        $detail = $detail !== '' ? '. ' . $detail : '';
        return $this->formErrorResponse($request, [[
            'field' => 'blocks_json',
            'key' => 'validation.blocks_json_invalid',
            'params' => [
                'detail' => $detail,
            ],
        ]], $page);
    }

    private function forbidden(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'pages.admin');
    }

    private function notFound(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'not_found', [], 404, [], 'pages.admin');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'pages.admin');
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

    private function blocksJsonDecodeDetail(): string
    {
        if (json_last_error() === JSON_ERROR_NONE) {
            return 'Expected a JSON array of blocks';
        }
        $message = json_last_error_msg();
        $message = $message !== '' ? $message : 'Invalid JSON';
        return 'Invalid JSON (' . $message . ')';
    }

    private function blocksJsonValidationDetail(BlockValidationException $error): string
    {
        $errors = $error->getErrors();
        if ($errors === []) {
            return '';
        }

        $parts = [];
        $limit = 3;
        foreach (array_slice($errors, 0, $limit) as $item) {
            $index = (int) ($item['index'] ?? 0) + 1;
            $field = (string) ($item['field'] ?? '');
            $message = (string) ($item['message'] ?? '');
            $label = 'Block ' . $index;
            if ($field !== '') {
                $label .= ' ' . $field;
            }
            $parts[] = trim($label . ': ' . $message);
        }

        $extra = count($errors) - $limit;
        if ($extra > 0) {
            $parts[] = '(+' . $extra . ' more)';
        }

        return implode('; ', $parts);
    }

    private function buildPageRow(array $page, bool $canEdit, ?string $query = null): array
    {
        $status = (string) ($page['status'] ?? 'draft');
        $isPublished = $status === 'published';
        $updatedAt = (string) ($page['updated_at'] ?? '');
        $ui = UiTokenMapper::mapPageRow([
            'status' => $status,
        ]);

        $title = (string) ($page['title'] ?? '');
        $slug = (string) ($page['slug'] ?? '');
        $titleSegments = Highlighter::segments($title, $query ?? '');
        $slugSegments = Highlighter::segments($slug, $query ?? '');

        return [
            'id' => (int) ($page['id'] ?? 0),
            'title' => $title,
            'slug' => $slug,
            'title_segments' => $titleSegments,
            'slug_segments' => $slugSegments,
            'status' => $status,
            'is_published' => $isPublished,
            'updated_at' => $updatedAt,
            'updated_at_display' => $updatedAt !== '' ? $updatedAt : '-',
            'can_edit' => $canEdit,
            'ui' => $ui,
        ];
    }

    private function generateErrorId(): string
    {
        return 'ERR-' . strtoupper(bin2hex(random_bytes(6)));
    }

    private function selectedAttr(bool $selected): SanitizedHtml
    {
        return SanitizedHtml::fromSanitized($selected ? 'selected' : '');
    }

    private function withSuccessTrigger(Response $response, string $messageKey): Response
    {
        return $response->withToastSuccess($messageKey, $this->view->translate($messageKey));
    }
}
