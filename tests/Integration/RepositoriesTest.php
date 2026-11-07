<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\TaskUser;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\TaskUserRepository;
use App\Tests\Support\Fixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoriesTest extends KernelTestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function claim(\App\Entity\Task $task, \App\Entity\User $user, string $week = '2026-W45', TaskStatus $status = TaskStatus::Pending, int $points = 0, ?string $deadline = null): TaskUser
    {
        $tu = new TaskUser($task, $user, $week, 'UTC', new \DateTimeImmutable('2026-11-04 10:00:00 UTC'));
        $tu->setStatus($status)->setPointsGained($points);
        if (null !== $deadline) {
            $tu->setDeadlineAt(new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')));
        }
        $this->em()->persist($tu);
        $this->em()->flush();

        return $tu;
    }

    public function testSeededTaskTypesExist(): void
    {
        $slugs = array_map(static fn ($t) => $t->getSlug(), static::getContainer()->get(\App\Repository\TaskTypeRepository::class)->findAll());
        foreach (['cleaning', 'cooking', 'pet', 'hygiene', 'medication', 'errands', 'others'] as $slug) {
            self::assertContains($slug, $slugs);
        }
    }

    public function testAvailableTasksAreSortedByUrgencyThenNewestAndHideInactive(): void
    {
        $creator = $this->makeUser();
        $low = $this->makeTask($creator, TaskPriority::Low, title: 'low');
        $urgent = $this->makeTask($creator, TaskPriority::Urgent, title: 'urgent');
        $high1 = $this->makeTask($creator, TaskPriority::High, title: 'high-old');
        $high2 = $this->makeTask($creator, TaskPriority::High, title: 'high-new');
        $hidden = $this->makeTask($creator, TaskPriority::Urgent, title: 'hidden');
        $hidden->setIsActive(false);
        $this->em()->flush();

        $titles = array_map(static fn ($t) => $t->getTitle(), static::getContainer()->get(TaskRepository::class)->findAvailable());

        self::assertSame(['urgent', 'high-new', 'high-old', 'low'], array_values(array_filter($titles, static fn ($t) => \in_array($t, ['low', 'urgent', 'high-old', 'high-new', 'hidden'], true))));
    }

    public function testOpenForUserAndOverdue(): void
    {
        $repo = static::getContainer()->get(TaskUserRepository::class);
        $user = $this->makeUser();
        $overdue = $this->claim($this->makeTask(title: 'a'), $user, deadline: '2026-11-04 09:00:00');
        $future = $this->claim($this->makeTask(title: 'b'), $user, deadline: '2026-11-04 12:00:00');
        $none = $this->claim($this->makeTask(title: 'c'), $user);
        $done = $this->claim($this->makeTask(title: 'd'), $user, status: TaskStatus::Completed, deadline: '2026-11-01 00:00:00');

        self::assertCount(3, $repo->findOpenForUser($user));
        self::assertCount(1, $repo->findRecentForUser($user));
        self::assertSame([$overdue->getId()], $repo->findOverdueIds(new \DateTimeImmutable('2026-11-04 10:00:00 UTC')));
        self::assertSame([], $repo->findOverdueIds(new \DateTimeImmutable('2026-11-04 09:00:00 UTC')), 'deadline is exclusive');
        self::assertSame([$overdue->getId(), $future->getId()], $repo->findOverdueIds(new \DateTimeImmutable('2026-11-05 00:00:00 UTC')));
        self::assertNotNull($none->getId());
        self::assertNotNull($done->getId());
    }

    public function testWeeklyTotalsAndLeaderboardCountOnlyCompletedInThatWeek(): void
    {
        $repo = static::getContainer()->get(TaskUserRepository::class);
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $this->claim($this->makeTask(title: 't1'), $alice, status: TaskStatus::Completed, points: 20);
        $this->claim($this->makeTask(title: 't2'), $alice, status: TaskStatus::Completed, points: 15);
        $this->claim($this->makeTask(title: 't3'), $alice, status: TaskStatus::Cancelled, points: 99);
        $this->claim($this->makeTask(title: 't4'), $alice, week: '2026-W44', status: TaskStatus::Completed, points: 50);
        $this->claim($this->makeTask(title: 't5'), $bob, status: TaskStatus::Completed, points: 40);

        self::assertSame(['points' => 35, 'completed' => 2], $repo->weeklyTotals($alice, '2026-W45'));
        self::assertSame(['points' => 50, 'completed' => 1], $repo->weeklyTotals($alice, '2026-W44'));
        self::assertSame(['points' => 0, 'completed' => 0], $repo->weeklyTotals($alice, '2026-W01'));

        $board = $repo->weeklyLeaderboard('2026-W45');
        self::assertSame([$bob->getId(), $alice->getId()], array_column($board, 'userId'));
        self::assertSame([40, 35], array_column($board, 'points'));
    }

    public function testUtcRoundTripPreservesInstantAndMicroseconds(): void
    {
        $user = $this->makeUser();
        $tu = new TaskUser($this->makeTask(), $user, '2026-W45', 'Asia/Tokyo', new \DateTimeImmutable('2026-11-04 19:00:00.123456', new \DateTimeZone('Asia/Tokyo')));
        $this->em()->persist($tu);
        $this->em()->flush();
        $id = $tu->getId();
        $this->em()->clear();

        $loaded = $this->em()->find(TaskUser::class, $id);
        self::assertSame('2026-11-04 10:00:00.123456', $loaded->getCreatedAt()->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $loaded->getCreatedAt()->getTimezone()->getName());
        $raw = $this->em()->getConnection()->fetchOne('SELECT created_at FROM task_user WHERE id = ?', [$id]);
        self::assertSame('2026-11-04 10:00:00.123456', $raw, 'stored as UTC in the database');
    }
}
