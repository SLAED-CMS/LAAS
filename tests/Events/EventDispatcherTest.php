<?php
declare(strict_types=1);

use Laas\Events\SimpleEventDispatcher;
use Laas\Events\StoppableEventInterface;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    public function testPriorityOrderingIsStable(): void
    {
        $dispatcher = new SimpleEventDispatcher();
        $event = new OrderTestEvent();

        $dispatcher->addListener(OrderTestEvent::class, static function (OrderTestEvent $event): void {
            $event->order[] = 'low';
        }, -10);
        $dispatcher->addListener(OrderTestEvent::class, static function (OrderTestEvent $event): void {
            $event->order[] = 'mid-1';
        }, 0);
        $dispatcher->addListener(OrderTestEvent::class, static function (OrderTestEvent $event): void {
            $event->order[] = 'mid-2';
        }, 0);
        $dispatcher->addListener(OrderTestEvent::class, static function (OrderTestEvent $event): void {
            $event->order[] = 'high';
        }, 10);

        $dispatcher->dispatch($event);

        $this->assertSame(['high', 'mid-1', 'mid-2', 'low'], $event->order);
    }

    public function testStopPropagationHaltsListeners(): void
    {
        $dispatcher = new SimpleEventDispatcher();
        $event = new StopTestEvent();

        $dispatcher->addListener(StopTestEvent::class, static function (StopTestEvent $event): void {
            $event->order[] = 'first';
            $event->stop();
        }, 10);
        $dispatcher->addListener(StopTestEvent::class, static function (StopTestEvent $event): void {
            $event->order[] = 'second';
        }, 0);

        $dispatcher->dispatch($event);

        $this->assertSame(['first'], $event->order);
    }

    public function testDispatchReturnsSameInstance(): void
    {
        $dispatcher = new SimpleEventDispatcher();
        $event = new OrderTestEvent();

        $returned = $dispatcher->dispatch($event);

        $this->assertSame($event, $returned);
    }
}

final class OrderTestEvent
{
    /** @var string[] */
    public array $order = [];
}

final class StopTestEvent implements StoppableEventInterface
{
    /** @var string[] */
    public array $order = [];
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
