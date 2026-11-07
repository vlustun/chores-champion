<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\TaskUser;
use App\Entity\WeeklyResult;
use App\Enum\TaskStatus;
use App\Exception\ValidationFailed;
use App\Service\WeeklySummaryService;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WeeklySummaryServiceTest extends KernelTestCase
{
    use Fixtures;

    private WeeklySummaryService $summary;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->summary = static::getContainer()->get(WeeklySummaryService::class);
    }

    private function done(\App\Entity\User $user, int $points, string $week = '2026-W45'): void
    {
        $tu = new TaskUser($this->makeTask(title: 't'.$points.$user->getId()), $user, $week, $user->getTimezone(), new \DateTimeImmutable('2026-11-04 10:00:00 UTC'));
        $tu->setStatus(TaskStatus::Completed)->setPointsGained($points);
        $this->em()->persist($tu);
        $this->em()->flush();
    }

    public function testLeaderboardSharesRankOnTies(): void
    {
        [$a, $b, $c] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $this->done($a, 30);
        $this->done($b, 30);
        $this->done($c, 10);

        $ranks = array_map(static fn ($r) => $r['rank'], $this->summary->leaderboard('2026-W45'));
        self::assertSame([1, 1, 3], $ranks);
    }

    public function testSnapshotIsIdempotentAndUsesUserTimezoneForWeekStart(): void
    {
        $vilnius = $this->makeUser('Europe/Vilnius');
        $this->done($vilnius, 25);

        self::assertSame(1, $this->summary->snapshot('2026-W45'));
        $this->done($vilnius, 5);
        self::assertSame(1, $this->summary->snapshot('2026-W45'), 'second run updates, not duplicates');

        $rows = $this->em()->getRepository(WeeklyResult::class)->findBy(['user' => $vilnius]);
        self::assertCount(1, $rows);
        self::assertSame(30, $rows[0]->getTotalPoints());
        self::assertSame(2, $rows[0]->getCompletedCount());
        self::assertSame(1, $rows[0]->getRank());
        self::assertSame('2026-11-01 22:00:00', $rows[0]->getWeekStartUtc()->format('Y-m-d H:i:s'), 'Monday 00:00 in UTC+2');
    }

    public function testCurrentWeekForUser(): void
    {
        $user = $this->makeUser('UTC');
        $at = new \DateTimeImmutable('2026-11-04 10:00:00 UTC');
        $this->done($user, 12);

        $week = $this->summary->currentWeek($user, $at);
        self::assertSame('2026-W45', $week['weekKey']);
        self::assertSame(12, $week['points']);
        self::assertSame('2026-11-02 00:00:00', $week['start']->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-09 00:00:00', $week['end']->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'garbage' => ['nope'];
        yield 'week 00' => ['2026-W00'];
        yield 'week 54' => ['2026-W54'];
        yield 'W53 in a 52-week year' => ['2025-W53'];
        yield 'sql' => ["2026-W45' OR 1=1"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testInvalidWeekKeysAreRejected(string $key): void
    {
        $this->expectException(ValidationFailed::class);
        $this->summary->leaderboard($key);
    }
}
