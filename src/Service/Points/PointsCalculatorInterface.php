<?php

declare(strict_types=1);

namespace App\Service\Points;

use App\Entity\TaskUser;

interface PointsCalculatorInterface
{
    /** Points earned when $task_user is completed at $completedAt. Never negative. */
    public function calculate(TaskUser $taskUser, \DateTimeImmutable $completedAt): int;
}
