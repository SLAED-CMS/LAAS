<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

use Laas\Events\EventDispatcherInterface;
use Laas\Events\Http\RequestEvent;
use Laas\Events\Http\ResponseEvent;
use Laas\Http\RequestId;

final class ObservabilityBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        try {
            $dispatcher = $ctx->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            return;
        }

        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $dispatcher->addListener(RequestEvent::class, static function (RequestEvent $event) use ($ctx): void {
            $start = microtime(true);
            $ctx->container->singleton('obs.request_start', static fn (): float => $start);

            $header = $event->request->getHeader('x-request-id');
            $normalized = RequestId::normalize($header);
            $requestId = $normalized ?? RequestId::generate();
            $ctx->container->singleton('obs.request_id', static fn (): string => $requestId);
            if ($normalized === null || $normalized !== $header) {
                $event->request = $event->request->withHeader('x-request-id', $requestId);
            }
        });

        $dispatcher->addListener(ResponseEvent::class, static function (ResponseEvent $event) use ($ctx): void {
            $response = $event->response;

            if ($ctx->debug && $response->getHeader('X-Response-Time-Ms') === null) {
                $start = null;
                try {
                    $start = $ctx->container->get('obs.request_start');
                } catch (\Throwable) {
                    $start = null;
                }
                if (is_float($start) || is_int($start)) {
                    $ms = (microtime(true) - (float) $start) * 1000;
                    $response = $response->withHeader('X-Response-Time-Ms', sprintf('%.2f', $ms));
                }
            }

            if ($response->getHeader('X-Request-Id') === null) {
                $requestId = null;
                try {
                    $requestId = $ctx->container->get('obs.request_id');
                } catch (\Throwable) {
                    $requestId = null;
                }
                if (!is_string($requestId) || $requestId === '') {
                    $requestId = RequestId::fromRequest($event->request);
                }
                if ($requestId !== '') {
                    $response = $response->withHeader('X-Request-Id', $requestId);
                }
            }

            $event->response = $response;
        });
    }
}
