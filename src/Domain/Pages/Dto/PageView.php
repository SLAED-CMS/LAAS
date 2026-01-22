<?php

declare(strict_types=1);

namespace Laas\Domain\Pages\Dto;

final class PageView
{
    /** @var array<int, array{type: string, data: array<string, mixed>}> */
    private array $blocks;
    /** @var array<int, array<string, mixed>> */
    private array $media;

    public function __construct(
        private int $id,
        private string $slug,
        private string $title,
        private string $content,
        private string $status,
        private string $updatedAt,
        private string $locale,
        array $blocks = [],
        array $media = []
    ) {
        $this->blocks = $blocks;
        $this->media = $media;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row, string $locale): self
    {
        return new self(
            self::normalizeId($row['id'] ?? null),
            (string) ($row['slug'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['content'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            $locale
        );
    }

    public static function fromSummary(PageSummary $summary, string $locale): self
    {
        return new self(
            $summary->id(),
            $summary->slug(),
            $summary->title(),
            $summary->content(),
            $summary->status(),
            $summary->updatedAt(),
            $locale
        );
    }

    /**
     * @param array<int, array{type: string, data: array<string, mixed>}> $blocks
     */
    public function withBlocks(array $blocks): self
    {
        $copy = clone $this;
        $copy->blocks = $blocks;
        return $copy;
    }

    /**
     * @param array<int, array<string, mixed>> $media
     */
    public function withMedia(array $media): self
    {
        $copy = clone $this;
        $copy->media = $media;
        return $copy;
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

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function media(): array
    {
        return $this->media;
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
            'locale' => $this->locale,
            'blocks' => $this->blocks,
            'media' => $this->media,
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
