<?php

declare(strict_types=1);

namespace Laas\Ai\Provider;

use Laas\Ai\Plan;
use Laas\Ai\Proposal;

final class LocalDemoProvider implements AiProviderInterface
{
    public function propose(array $input): array
    {
        $proposal = new Proposal([
            'id' => bin2hex(random_bytes(16)),
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'remote.demo',
            'summary' => 'Local demo proposal',
            'file_changes' => [],
            'entity_changes' => [],
            'warnings' => ['demo provider'],
            'confidence' => 0.2,
            'risk' => 'low',
        ]);

        $plan = new Plan([
            'id' => bin2hex(random_bytes(16)),
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'remote.demo',
            'summary' => 'Local demo plan',
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Policy check',
                    'command' => 'policy:check',
                    'args' => [],
                ],
            ],
            'confidence' => 0.2,
            'risk' => 'low',
        ]);

        return [
            'proposal' => $proposal->toArray(),
            'plan' => $plan->toArray(),
        ];
    }
}
