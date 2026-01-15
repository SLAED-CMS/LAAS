<?php
declare(strict_types=1);

namespace Laas\Ai;

use DateTimeImmutable;
use InvalidArgumentException;

final class Proposal
{
    private string $id;
    private string $createdAt;
    private string $kind;
    private string $summary;
    private array $fileChanges;
    private array $entityChanges;
    private array $warnings;
    private float $confidence;
    private string $risk;

    public function __construct(
        array|string $dataOrId,
        ?string $createdAt = null,
        ?string $kind = null,
        ?string $summary = null,
        array $fileChanges = [],
        array $entityChanges = [],
        array $warnings = [],
        ?float $confidence = null,
        ?string $risk = null
    ) {
        if (is_array($dataOrId)) {
            $this->assignFromArray($dataOrId);
            return;
        }

        if ($createdAt === null || $kind === null || $summary === null || $confidence === null || $risk === null) {
            throw new InvalidArgumentException('Missing required proposal fields.');
        }

        $this->assignFromArray([
            'id' => $dataOrId,
            'created_at' => $createdAt,
            'kind' => $kind,
            'summary' => $summary,
            'file_changes' => $fileChanges,
            'entity_changes' => $entityChanges,
            'warnings' => $warnings,
            'confidence' => $confidence,
            'risk' => $risk,
        ]);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'file_changes' => $this->fileChanges,
            'entity_changes' => $this->entityChanges,
            'warnings' => $this->warnings,
            'confidence' => $this->confidence,
            'risk' => $this->risk,
        ];
    }

    private function assignFromArray(array $data): void
    {
        $required = [
            'id',
            'created_at',
            'kind',
            'summary',
            'file_changes',
            'entity_changes',
            'warnings',
            'confidence',
            'risk',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Missing required key: ' . $key);
            }
        }

        $id = (string) $data['id'];
        if ($id === '') {
            throw new InvalidArgumentException('Proposal id is required.');
        }

        $createdAt = (string) $data['created_at'];
        if ($createdAt === '' || DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt) === false) {
            throw new InvalidArgumentException('created_at must be ISO8601 (DATE_ATOM).');
        }

        $kind = (string) $data['kind'];
        if ($kind === '') {
            throw new InvalidArgumentException('Proposal kind is required.');
        }

        $summary = (string) $data['summary'];
        if ($summary === '') {
            throw new InvalidArgumentException('Proposal summary is required.');
        }

        $fileChanges = $data['file_changes'];
        if (!is_array($fileChanges)) {
            throw new InvalidArgumentException('file_changes must be an array.');
        }

        $entityChanges = $data['entity_changes'];
        if (!is_array($entityChanges)) {
            throw new InvalidArgumentException('entity_changes must be an array.');
        }

        $warnings = $data['warnings'];
        if (!is_array($warnings)) {
            throw new InvalidArgumentException('warnings must be an array.');
        }

        $confidenceRaw = $data['confidence'];
        if (!is_numeric($confidenceRaw)) {
            throw new InvalidArgumentException('confidence must be a number.');
        }
        $confidence = (float) $confidenceRaw;
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be between 0 and 1.');
        }

        $risk = (string) $data['risk'];
        if (!in_array($risk, ['low', 'medium', 'high'], true)) {
            throw new InvalidArgumentException('risk must be low, medium, or high.');
        }

        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->kind = $kind;
        $this->summary = $summary;
        $this->fileChanges = $fileChanges;
        $this->entityChanges = $entityChanges;
        $this->warnings = $warnings;
        $this->confidence = $confidence;
        $this->risk = $risk;
    }
}
