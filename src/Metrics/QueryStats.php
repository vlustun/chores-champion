<?php

declare(strict_types=1);

namespace App\Metrics;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Per-request (per-process) DB query counter fed by the DBAL middleware. */
final class QueryStats
{
    private int $count = 0;
    private float $totalMs = 0.0;

    public function __construct(
        #[Autowire(service: 'monolog.logger.performance')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(int:APP_SLOW_QUERY_MS)%')]
        private readonly int $slowQueryMs = 100,
    ) {
    }

    public function record(string $sql, float $ms): void
    {
        ++$this->count;
        $this->totalMs += $ms;
        if ($ms >= $this->slowQueryMs) {
            // SQL text only (placeholders, no bound values => no PII)
            $this->logger->warning('Slow query', ['ms' => round($ms, 1), 'sql' => mb_substr($sql, 0, 500)]);
        }
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->totalMs = 0.0;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function totalMs(): float
    {
        return $this->totalMs;
    }
}
