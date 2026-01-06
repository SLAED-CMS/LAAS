<?php
declare(strict_types=1);

namespace Laas\Modules\Changelog\Dto;

final class ChangelogCommit
{
    public function __construct(
        public string $sha,
        public string $shortSha,
        public string $title,
        public string $body,
        public string $authorName,
        public ?string $authorEmail,
        public string $committedAt,
        public ?string $url
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sha' => $this->sha,
            'short_sha' => $this->shortSha,
            'title' => $this->title,
            'body' => $this->body,
            'author_name' => $this->authorName,
            'author_email' => $this->authorEmail,
            'committed_at' => $this->committedAt,
            'url' => $this->url,
        ];
    }
}
