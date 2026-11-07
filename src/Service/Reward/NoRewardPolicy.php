<?php

declare(strict_types=1);

namespace App\Service\Reward;

use App\Entity\WeeklyResult;

final class NoRewardPolicy implements WeeklyRewardPolicyInterface
{
    public function rewardsFor(WeeklyResult $result): array
    {
        return [];
    }
}
