<?php
declare(strict_types=1);

namespace Laas\Domain\Security;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\SecurityReportsRepository;
use RuntimeException;
use Throwable;

class SecurityReportsService implements SecurityReportsServiceInterface, SecurityReportsReadServiceInterface, SecurityReportsWriteServiceInterface
{
    private ?SecurityReportsRepository $repository = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be zero or positive.');
        }

        $rows = $this->repository()->list($this->normalizeFilters($filters), $limit, $offset);
        $level = $this->normalizeLevel($filters['level'] ?? null);
        if ($level === null) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($level): bool {
            return $this->reportLevel($row) === $level;
        }));
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $id = trim($id);
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        return $this->repository()->findById((int) $id);
    }

    public function count(array $filters = []): int
    {
        $level = $this->normalizeLevel($filters['level'] ?? null);
        if ($level === null) {
            return $this->repository()->count($this->normalizeFilters($filters));
        }

        $repoFilters = $this->normalizeFilters($filters);
        $limit = 200;
        $offset = 0;
        $count = 0;

        while (true) {
            $rows = $this->repository()->list($repoFilters, $limit, $offset);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($this->reportLevel($row) === $level) {
                    $count++;
                }
            }

            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }

        return $count;
    }

    /** @return array<string, int> */
    public function countByStatus(array $filters = []): array
    {
        return $this->repository()->countByStatus($this->normalizeFilters($filters));
    }

    /** @return array<string, int> */
    public function countByType(array $filters = []): array
    {
        return $this->repository()->countByType($this->normalizeFilters($filters));
    }

    /** @mutation */
    public function updateStatus(int $id, string $status): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Report id must be positive.');
        }

        return $this->repository()->updateStatus($id, $status);
    }

    /** @mutation */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Report id must be positive.');
        }

        return $this->repository()->delete($id);
    }

    /**
     * @param array<string, mixed> $data
     * @mutation
     */
    public function insert(array $data): void
    {
        $this->repository()->insert($data);
    }

    /** @mutation */
    public function prune(int $days): int
    {
        return $this->repository()->prune($days);
    }

    private function repository(): SecurityReportsRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new SecurityReportsRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }

    /** @return array<string, mixed> */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        $type = $this->normalizeSource($filters['type'] ?? null, $filters['source'] ?? null);
        if ($type !== null) {
            $normalized['type'] = $type;
        }

        $status = $this->normalizeStatus($filters['status'] ?? null);
        if ($status !== null) {
            $normalized['status'] = $status;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $normalized['search'] = $search;
        }

        $since = $this->normalizeSince($filters['since'] ?? null);
        if ($since !== null) {
            $normalized['since'] = $since;
        }

        return $normalized;
    }

    private function normalizeSource(mixed $type, mixed $source): ?string
    {
        $candidates = [];
        if (is_string($type)) {
            $candidates[] = $type;
        }
        if (is_string($source)) {
            $candidates[] = $source;
        }

        foreach ($candidates as $candidate) {
            $value = strtolower(trim($candidate));
            if (in_array($value, ['csp', 'other'], true)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        $status = strtolower(trim($status));
        if (in_array($status, ['new', 'triaged', 'ignored'], true)) {
            return $status;
        }

        return null;
    }

    private function normalizeSince(mixed $since): ?string
    {
        if ($since instanceof DateTimeInterface) {
            return $since->format('Y-m-d H:i:s');
        }

        if (!is_string($since)) {
            return null;
        }

        $value = trim($since);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeLevel(mixed $level): ?string
    {
        if (!is_string($level)) {
            return null;
        }

        $level = strtolower(trim($level));
        return in_array($level, ['info', 'warn', 'error'], true) ? $level : null;
    }

    private function reportLevel(array $row): string
    {
        $directive = strtolower((string) ($row['violated_directive'] ?? ''));
        $type = strtolower((string) ($row['type'] ?? ''));

        $high = [
            'script-src',
            'object-src',
            'base-uri',
            'frame-ancestors',
            'trusted-types',
            'require-trusted-types-for',
        ];
        foreach ($high as $needle) {
            if ($directive !== '' && str_contains($directive, $needle)) {
                return 'error';
            }
        }

        $medium = [
            'style-src',
            'connect-src',
            'img-src',
            'font-src',
            'frame-src',
            'child-src',
            'worker-src',
            'manifest-src',
        ];
        foreach ($medium as $needle) {
            if ($directive !== '' && str_contains($directive, $needle)) {
                return 'warn';
            }
        }

        if ($type !== '' && $type !== 'csp') {
            return 'warn';
        }

        return 'info';
    }
}
