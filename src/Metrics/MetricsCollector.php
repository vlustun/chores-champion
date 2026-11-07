<?php

declare(strict_types=1);

namespace App\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;

/**
 * Thin wrapper over promphp. Storage: APCu when available (shared by all
 * PHP-FPM workers), in-memory otherwise (CLI / tests / php -S).
 * Metrics must never break a request: every public method swallows failures.
 */
class MetricsCollector
{
    public const NS = 'chores';

    /** Seconds; web request latency buckets. */
    private const LATENCY_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    private CollectorRegistry $registry;

    public function __construct(?Adapter $storage = null)
    {
        $this->registry = new CollectorRegistry($storage ?? self::defaultStorage(), false);
    }

    public static function defaultStorage(): Adapter
    {
        if (\function_exists('apcu_enabled') && apcu_enabled()) {
            return new APC();
        }

        return new InMemory();
    }

    /** @param array<string, string> $labels */
    public function inc(string $name, array $labels = [], int|float $by = 1, string $help = ''): void
    {
        try {
            $this->registry
                ->getOrRegisterCounter(self::NS, $name, '' !== $help ? $help : $name, array_keys($labels))
                ->incBy($by, array_values($labels));
        } catch (\Throwable) {
            // metrics are best-effort
        }
    }

    /**
     * @param array<string, string> $labels
     * @param list<float>|null      $buckets
     */
    public function observe(string $name, float $value, array $labels = [], ?array $buckets = null, string $help = ''): void
    {
        try {
            $this->registry
                ->getOrRegisterHistogram(self::NS, $name, '' !== $help ? $help : $name, array_keys($labels), $buckets ?? self::LATENCY_BUCKETS)
                ->observe($value, array_values($labels));
        } catch (\Throwable) {
        }
    }

    /** @param array<string, string> $labels */
    public function gauge(string $name, float $value, array $labels = [], string $help = ''): void
    {
        try {
            $this->registry
                ->getOrRegisterGauge(self::NS, $name, '' !== $help ? $help : $name, array_keys($labels))
                ->set($value, array_values($labels));
        } catch (\Throwable) {
        }
    }

    // --- business metrics -------------------------------------------------

    public function taskClaimed(): void
    {
        $this->inc('tasks_claimed_total', help: 'Tasks claimed by users');
    }

    public function taskCompleted(int $points): void
    {
        $this->inc('tasks_completed_total', help: 'Tasks completed');
        $this->inc('points_awarded_total', by: $points, help: 'Points awarded');
    }

    public function taskTransition(string $to): void
    {
        $this->inc('task_transitions_total', ['to' => $to], help: 'Task status transitions');
    }

    public function error(string $kind): void
    {
        $this->inc('errors_total', ['kind' => $kind], help: 'Handled errors by kind');
    }

    public function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
