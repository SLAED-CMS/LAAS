<?php

declare(strict_types=1);

namespace Laas\Ai;

use DateTimeImmutable;
use InvalidArgumentException;

final class Plan
{
    private string $id;
    private string $createdAt;
    private string $kind;
    private string $summary;
    private array $steps;
    private float $confidence;
    private string $risk;

    public function __construct(
        array|string $dataOrId,
        ?string $createdAt = null,
        ?string $kind = null,
        ?string $summary = null,
        array $steps = [],
        ?float $confidence = null,
        ?string $risk = null
    ) {
        if (is_array($dataOrId)) {
            $this->assignFromArray($dataOrId);
            return;
        }

        if ($createdAt === null || $kind === null || $summary === null || $confidence === null || $risk === null) {
            throw new InvalidArgumentException('Missing required plan fields.');
        }

        $this->assignFromArray([
            'id' => $dataOrId,
            'created_at' => $createdAt,
            'kind' => $kind,
            'summary' => $summary,
            'steps' => $steps,
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
            'steps' => $this->steps,
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
            'steps',
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
            throw new InvalidArgumentException('Plan id is required.');
        }

        $createdAt = (string) $data['created_at'];
        if ($createdAt === '' || DateTimeImmutable::createFromFormat(DATE_ATOM, $createdAt) === false) {
            throw new InvalidArgumentException('created_at must be ISO8601 (DATE_ATOM).');
        }

        $kind = (string) $data['kind'];
        if ($kind === '') {
            throw new InvalidArgumentException('Plan kind is required.');
        }

        $summary = (string) $data['summary'];
        if ($summary === '') {
            throw new InvalidArgumentException('Plan summary is required.');
        }

        $steps = $data['steps'];
        if (!is_array($steps)) {
            throw new InvalidArgumentException('steps must be an array.');
        }
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new InvalidArgumentException('steps[' . $index . '] must be an array.');
            }
            foreach (['id', 'title', 'command', 'args'] as $key) {
                if (!array_key_exists($key, $step)) {
                    throw new InvalidArgumentException('steps[' . $index . '] missing key: ' . $key);
                }
            }

            $stepId = (string) $step['id'];
            if ($stepId === '') {
                throw new InvalidArgumentException('steps[' . $index . '].id is required.');
            }

            $title = (string) $step['title'];
            if ($title === '') {
                throw new InvalidArgumentException('steps[' . $index . '].title is required.');
            }

            $command = (string) $step['command'];
            if ($command === '') {
                throw new InvalidArgumentException('steps[' . $index . '].command is required.');
            }

            $args = $step['args'];
            if (!is_array($args)) {
                throw new InvalidArgumentException('steps[' . $index . '].args must be an array.');
            }
            foreach ($args as $argIndex => $arg) {
                if (!is_string($arg)) {
                    throw new InvalidArgumentException('steps[' . $index . '].args[' . $argIndex . '] must be a string.');
                }
            }
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
        $this->steps = $steps;
        $this->confidence = $confidence;
        $this->risk = $risk;
    }
}
