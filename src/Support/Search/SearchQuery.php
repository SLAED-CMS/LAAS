<?php

declare(strict_types=1);

namespace Laas\Support\Search;

final class SearchQuery
{
    public string $q;
    public int $limit;
    public int $page;
    public int $offset;
    public string $scope;

    public function __construct(string $q, int $limit, int $page, string $scope)
    {
        $this->q = SearchNormalizer::normalize($q);
        $this->limit = max(1, min(50, $limit));
        $this->page = max(1, min(1000, $page));
        $this->offset = ($this->page - 1) * $this->limit;
        $this->scope = $scope;
    }
}
