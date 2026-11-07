<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\WeeklyResult;
use App\Exception\ValidationFailed;
use App\Repository\TaskUserRepository;
use App\Repository\UserRepository;
use App\Repository\WeeklyResultRepository;
use App\Service\Time\UserClock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates completed points per user per *local* week (task_user.week_key
 * is fixed at claim time from the user's timezone).
 */
class WeeklySummaryService
{
    public function __construct(
        private readonly TaskUserRepository $taskUsers,
        private readonly UserRepository $users,
        private readonly WeeklyResultRepository $results,
        private readonly EntityManagerInterface $em,
        private readonly UserClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{weekKey: string, start: \DateTimeImmutable, end: \DateTimeImmutable, points: int, completed: int} */
    public function currentWeek(User $user, ?\DateTimeImmutable $at = null): array
    {
        $at ??= $this->clock->nowUtc();
        $tz = $user->getTimezone();
        $key = $this->clock->weekKey($at, $tz);
        $totals = $this->taskUsers->weeklyTotals($user, $key);

        return [
            'weekKey' => $key,
            'start' => $this->clock->weekStart($at, $tz),
            'end' => $this->clock->weekEnd($at, $tz),
            'points' => $totals['points'],
            'completed' => $totals['completed'],
        ];
    }

    /**
     * Household ranking for a week (dense ties share a rank).
     *
     * @return list<array{user: User, points: int, completed: int, rank: int}>
     */
    public function leaderboard(string $weekKey): array
    {
        $this->assertWeekKey($weekKey);
        $rows = [];
        $rank = 0;
        $prevPoints = null;
        $position = 0;
        foreach ($this->taskUsers->weeklyLeaderboard($weekKey) as $row) {
            $user = $this->users->find($row['userId']);
            if (null === $user) {
                continue;
            }
            ++$position;
            if ($row['points'] !== $prevPoints) {
                $rank = $position;
                $prevPoints = $row['points'];
            }
            $rows[] = ['user' => $user, 'points' => $row['points'], 'completed' => $row['completed'], 'rank' => $rank];
        }

        return $rows;
    }

    /**
     * Idempotently materialise WeeklyResult rows (call after a week has ended, e.g. from a scheduler).
     * Foundation for the end-of-week rewards.
     *
     * @return int rows written or updated
     */
    public function snapshot(string $weekKey): int
    {
        $count = 0;
        foreach ($this->leaderboard($weekKey) as $row) {
            $user = $row['user'];
            $result = $this->results->findOneByUserAndWeek($user, $weekKey);
            if (null === $result) {
                $start = $this->clock->weekStartFromKey($weekKey, $user->getTimezone());
                if (null === $start) {
                    continue;
                }
                $result = new WeeklyResult($user, $weekKey, $start);
                $this->em->persist($result);
            }
            $result->setTotalPoints($row['points'])->setCompletedCount($row['completed'])->setRank($row['rank']);
            ++$count;
        }
        $this->em->flush();
        $this->logger->info('Weekly snapshot written', ['week' => $weekKey, 'rows' => $count]);

        return $count;
    }

    private function assertWeekKey(string $weekKey): void
    {
        if (null === $this->clock->weekStartFromKey($weekKey, 'UTC')) {
            throw new ValidationFailed(\sprintf('Invalid week key "%s" (expected e.g. 2026-W45).', mb_substr($weekKey, 0, 20)));
        }
    }
}
