<?php

declare(strict_types=1);

namespace App\Service\Reward;

use App\Entity\WeeklyResult;

/** Extension point: decide what a weekly result earns. Real rewards are designed in iteration 2. */
interface WeeklyRewardPolicyInterface
{
    /** @return list<string> reward identifiers earned for this result */
    public function rewardsFor(WeeklyResult $result): array;
}
