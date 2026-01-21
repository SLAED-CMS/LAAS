<?php
declare(strict_types=1);

namespace Laas\Domain\Pages\Dto;

final class PageSummary
{
    public function __construct(
        private int $id,
        private string $slug,
        private string $title,
        private string $content,
        private string $status,
        private string $updatedAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            self::normalizeId($row['id'] ?? null),
            (string) ($row['slug'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['content'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function normalizeId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return 0;
    }
}
