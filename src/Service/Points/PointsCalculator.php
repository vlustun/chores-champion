<?php

declare(strict_types=1);

namespace App\Service\Points;

use App\Entity\TaskUser;

/**
 * Iteration-1 formula: round(reward_value x priority multiplier) + on-time bonus.
 * Tasks without reward_value are worth DEFAULT_BASE points. Integer maths only.
 * The on-time bonus is a placeholder (0) until rewards are designed.
 */
final class PointsCalculator implements PointsCalculatorInterface
{
    public const DEFAULT_BASE = 10;

    public function calculate(TaskUser $taskUser, \DateTimeImmutable $completedAt): int
    {
        $task = $taskUser->getTask();
        $base = max(0, $task->getRewardValue() ?? self::DEFAULT_BASE);
        $scaled = $base * $task->getPriority()->multiplierTenths();
        $points = intdiv($scaled + 5, 10); // round half up

        return max(0, $points + $this->onTimeBonus($taskUser, $completedAt));
    }

    private function onTimeBonus(TaskUser $taskUser, \DateTimeImmutable $completedAt): int
    {
        return 0;
    }
}
