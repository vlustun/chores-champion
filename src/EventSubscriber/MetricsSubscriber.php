<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Metrics\MetricsCollector;
use App\Metrics\QueryStats;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Request duration (histogram per route), memory, DB query count; warns on slow requests. */
final class MetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly QueryStats $queries,
        #[Autowire(service: 'monolog.logger.performance')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(int:APP_SLOW_REQUEST_MS)%')]
        private readonly int $slowRequestMs = 800,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::TERMINATE => ['onTerminate', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->queries->reset();
            $event->getRequest()->attributes->set('_started_at', microtime(true));
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        // low-cardinality label: route name, never the raw path (ids/scanners would explode label sets)
        $route = \is_string($route) && '' !== $route ? $route : 'unmatched';
        if ('metrics' === $route) {
            return;
        }
        $started = $request->attributes->get('_started_at');
        $seconds = \is_float($started) ? microtime(true) - $started : 0.0;
        $status = $event->getResponse()->getStatusCode();
        $labels = ['route' => $route, 'method' => $request->getMethod(), 'status' => (string) $status];

        $this->metrics->inc('http_requests_total', $labels, help: 'HTTP requests');
        $this->metrics->observe('http_request_duration_seconds', $seconds, ['route' => $route], help: 'HTTP request latency');
        $this->metrics->observe('db_queries_per_request', (float) $this->queries->count(), ['route' => $route], [1, 2, 5, 10, 20, 50, 100], 'DB queries per request');
        $this->metrics->gauge('memory_peak_bytes', (float) memory_get_peak_usage(true), help: 'Peak memory of last request');

        $context = [
            'route' => $route,
            'status' => $status,
            'ms' => round($seconds * 1000, 1),
            'queries' => $this->queries->count(),
            'db_ms' => round($this->queries->totalMs(), 1),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
        if ($seconds * 1000 >= $this->slowRequestMs) {
            $this->logger->warning('Slow request', $context);
        } else {
            $this->logger->info('Request', $context);
        }
    }
}
