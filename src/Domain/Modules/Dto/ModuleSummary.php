<?php
declare(strict_types=1);

namespace Laas\Domain\Modules\Dto;

final class ModuleSummary
{
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = [
            'name' => (string) ($data['name'] ?? ''),
            'key' => (string) ($data['key'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'module_id' => (string) ($data['module_id'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'enabled' => (bool) ($data['enabled'] ?? false),
            'admin_url' => isset($data['admin_url']) ? (string) $data['admin_url'] : null,
            'details_anchor' => (string) ($data['details_anchor'] ?? ''),
            'details_url' => (string) ($data['details_url'] ?? ''),
            'notes' => (string) ($data['notes'] ?? ''),
            'virtual' => (bool) ($data['virtual'] ?? false),
            'icon' => (string) ($data['icon'] ?? ''),
            'actions' => is_array($data['actions'] ?? null) ? $data['actions'] : [],
            'actions_nav' => is_array($data['actions_nav'] ?? null) ? $data['actions_nav'] : [],
            'version' => isset($data['version']) ? (string) $data['version'] : null,
            'installed_at' => isset($data['installed_at']) ? (string) $data['installed_at'] : null,
            'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            'protected' => (bool) ($data['protected'] ?? false),
            'source' => (string) ($data['source'] ?? ''),
            'type_is_internal' => (bool) ($data['type_is_internal'] ?? false),
            'type_is_admin' => (bool) ($data['type_is_admin'] ?? false),
            'type_is_api' => (bool) ($data['type_is_api'] ?? false),
            'group' => (string) ($data['group'] ?? ''),
            'pinned' => (bool) ($data['pinned'] ?? false),
            'nav_priority' => (int) ($data['nav_priority'] ?? 0),
            'nav_label' => (string) ($data['nav_label'] ?? ''),
            'nav_badge' => (string) ($data['nav_badge'] ?? ''),
            'nav_badge_tone' => (string) ($data['nav_badge_tone'] ?? ''),
            'nav_search' => (string) ($data['nav_search'] ?? ''),
        ];

        return new self($payload);
    }

    public function name(): string
    {
        return (string) $this->payload['name'];
    }

    public function moduleId(): string
    {
        return (string) $this->payload['module_id'];
    }

    public function type(): string
    {
        return (string) $this->payload['type'];
    }

    public function enabled(): bool
    {
        return (bool) $this->payload['enabled'];
    }

    public function notes(): string
    {
        return (string) $this->payload['notes'];
    }

    public function adminUrl(): ?string
    {
        $value = $this->payload['admin_url'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function detailsAnchor(): string
    {
        return (string) $this->payload['details_anchor'];
    }

    public function detailsUrl(): string
    {
        return (string) $this->payload['details_url'];
    }

    public function version(): ?string
    {
        $value = $this->payload['version'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function protected(): bool
    {
        return (bool) $this->payload['protected'];
    }

    public function icon(): string
    {
        return (string) $this->payload['icon'];
    }

    public function actions(): array
    {
        return is_array($this->payload['actions'] ?? null) ? $this->payload['actions'] : [];
    }

    public function actionsNav(): array
    {
        return is_array($this->payload['actions_nav'] ?? null) ? $this->payload['actions_nav'] : [];
    }

    public function typeIsInternal(): bool
    {
        return (bool) $this->payload['type_is_internal'];
    }

    public function typeIsAdmin(): bool
    {
        return (bool) $this->payload['type_is_admin'];
    }

    public function typeIsApi(): bool
    {
        return (bool) $this->payload['type_is_api'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
