<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskStatusTest extends TestCase
{
    /** @return iterable<string, array{TaskStatus, TaskStatus, bool}> */
    public static function matrix(): iterable
    {
        $allowed = [
            'pending' => ['in_progress', 'cancelled', 'expired'],
            'in_progress' => ['completed', 'cancelled', 'expired'],
            'completed' => [],
            'cancelled' => [],
            'expired' => [],
        ];
        foreach (TaskStatus::cases() as $from) {
            foreach (TaskStatus::cases() as $to) {
                yield "{$from->value} -> {$to->value}" => [$from, $to, \in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testTransitions(TaskStatus $from, TaskStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    public function testOpenAndTerminal(): void
    {
        self::assertTrue(TaskStatus::Pending->isOpen());
        self::assertTrue(TaskStatus::InProgress->isOpen());
        foreach ([TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Expired] as $s) {
            self::assertTrue($s->isTerminal());
        }
        self::assertSame(['pending', 'in_progress'], TaskStatus::openValues());
    }

    public function testPriorityWeightsAreStrictlyIncreasing(): void
    {
        $weights = array_map(static fn (TaskPriority $p) => $p->weight(), TaskPriority::cases());
        $sorted = $weights;
        sort($sorted);
        self::assertSame($sorted, $weights);
        self::assertSame($weights, array_unique($weights));
    }
}
