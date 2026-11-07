<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\TaskView;
use App\Entity\Task;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use PHPUnit\Framework\TestCase;

final class TaskViewTest extends TestCase
{
    public function testSerializesWithSnakeCaseKeysAndNoType(): void
    {
        $task = (new Task())->setTitle('Feed cat')->setPriority(TaskPriority::High)->setRecurrenceKind(RecurrenceKind::Reusable)->setRewardValue(5);
        $json = TaskView::fromEntity($task)->jsonSerialize();

        self::assertSame('Feed cat', $json['title']);
        self::assertSame('high', $json['priority']);
        self::assertSame('reusable', $json['recurrence_kind']);
        self::assertSame(5, $json['reward_value']);
        self::assertNull($json['type']);
        self::assertStringEndsWith('+00:00', $json['created_at']);
    }
}
