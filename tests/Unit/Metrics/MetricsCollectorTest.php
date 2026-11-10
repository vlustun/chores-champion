<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metrics;

use App\Metrics\MetricsCollector;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;

final class MetricsCollectorTest extends TestCase
{
    public function testCountersHistogramsAndRendering(): void
    {
        $m = new MetricsCollector(new InMemory());
        $m->taskClaimed();
        $m->taskClaimed();
        $m->taskCompleted(30);
        $m->observe('http_request_duration_seconds', 0.04, ['route' => 'app_tasks']);

        $text = $m->render();

        self::assertStringContainsString('chores_tasks_claimed_total 2', $text);
        self::assertStringContainsString('chores_tasks_completed_total 1', $text);
        self::assertStringContainsString('chores_points_awarded_total 30', $text);
        self::assertStringContainsString('chores_http_request_duration_seconds_bucket{route="app_tasks",le="0.05"} 1', $text);
    }

    public function testNeverThrows(): void
    {
        $m = new MetricsCollector(new InMemory());
        $m->inc('same_name', ['a' => 'x']);
        $m->inc('same_name', ['different' => 'labels']); // label mismatch would throw inside promphp
        $this->expectNotToPerformAssertions();
    }
}
