<?php
declare(strict_types=1);

use Laas\Ai\Plan;
use Laas\Ai\PlanRunner;
use PHPUnit\Framework\TestCase;

final class PlanRunnerTest extends TestCase
{
    public function testForbiddenCommandFails(): void
    {
        $plan = new Plan([
            'id' => 'plan_1',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Forbidden command',
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Forbidden',
                    'command' => 'system:rm',
                    'args' => [],
                ],
            ],
            'confidence' => 0.4,
            'risk' => 'low',
        ]);

        $runner = new PlanRunner(dirname(__DIR__));
        $result = $runner->run($plan, true, false);

        $this->assertSame(1, $result['failed'] ?? null);
        $this->assertSame('blocked', $result['outputs'][0]['status'] ?? null);
    }

    public function testBadArgFails(): void
    {
        $plan = new Plan([
            'id' => 'plan_2',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Bad arg',
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Policy',
                    'command' => 'policy:check',
                    'args' => ['&&'],
                ],
            ],
            'confidence' => 0.6,
            'risk' => 'low',
        ]);

        $runner = new PlanRunner(dirname(__DIR__));
        $result = $runner->run($plan, true, false);

        $this->assertSame(1, $result['failed'] ?? null);
        $this->assertSame('invalid_args', $result['outputs'][0]['status'] ?? null);
    }

    public function testDryRunDoesNotExecute(): void
    {
        $plan = new Plan([
            'id' => 'plan_3',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Dry-run',
            'steps' => [
                [
                    'id' => 's1',
                    'title' => 'Policy',
                    'command' => 'policy:check',
                    'args' => [],
                ],
            ],
            'confidence' => 0.6,
            'risk' => 'low',
        ]);

        $runner = new PlanRunner(dirname(__DIR__));
        $result = $runner->run($plan, true, false);

        $this->assertSame(0, $result['steps_run'] ?? null);
        $this->assertSame('dry-run', $result['outputs'][0]['status'] ?? null);
    }
}
