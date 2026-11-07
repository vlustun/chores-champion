<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Task;
use App\Entity\TaskUser;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Service\Points\PointsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PointsCalculatorTest extends TestCase
{
    /** @return iterable<string, array{?int, TaskPriority, int}> */
    public static function cases(): iterable
    {
        yield 'low x1' => [10, TaskPriority::Low, 10];
        yield 'medium x1.5' => [10, TaskPriority::Medium, 15];
        yield 'medium rounds half up' => [5, TaskPriority::Medium, 8]; // 7.5 -> 8
        yield 'high x2' => [10, TaskPriority::High, 20];
        yield 'urgent x3' => [10, TaskPriority::Urgent, 30];
        yield 'zero reward' => [0, TaskPriority::Urgent, 0];
        yield 'null reward uses default' => [null, TaskPriority::Low, PointsCalculator::DEFAULT_BASE];
        yield 'big reward no overflow' => [Task::MAX_REWARD, TaskPriority::Urgent, 3000];
    }

    #[DataProvider('cases')]
    public function testCalculate(?int $reward, TaskPriority $priority, int $expected): void
    {
        $task = (new Task())->setPriority($priority)->setRewardValue($reward);
        $tu = new TaskUser($task, new User(), '2026-W45', 'UTC', new \DateTimeImmutable());

        self::assertSame($expected, (new PointsCalculator())->calculate($tu, new \DateTimeImmutable()));
    }
}
