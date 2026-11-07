<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\TaskUser;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Exception\ForbiddenAction;
use App\Exception\InvalidDeadline;
use App\Exception\InvalidParentTask;
use App\Exception\InvalidStatusTransition;
use App\Exception\TaskAlreadyClaimed;
use App\Exception\TaskNotAvailable;
use App\Exception\TaskNotFound;
use App\Metrics\MetricsCollector;
use App\Service\Points\PointsCalculator;
use App\Service\TaskWorkflowService;
use App\Service\Time\TimezoneResolver;
use App\Service\Time\UserClock;
use App\Tests\Support\Fixtures;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class TaskWorkflowServiceTest extends KernelTestCase
{
    use Fixtures;

    private MockClock $clock;
    private TaskWorkflowService $workflow;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-11-04 10:00:00 UTC'); // Wednesday
        $userClock = new UserClock($this->clock, new TimezoneResolver($this->em(), new NullLogger(), 'UTC'));
        $this->workflow = new TaskWorkflowService(
            static::getContainer()->get('doctrine'),
            $userClock,
            new PointsCalculator(),
            new MetricsCollector(new \Prometheus\Storage\InMemory()),
            new NullLogger(),
        );
    }

    public function testClaimSnapshotsWeekTimezoneAndDeadlineInUtc(): void
    {
        $user = $this->makeUser('Europe/Vilnius'); // UTC+2 in November
        $task = $this->makeTask();

        $tu = $this->workflow->claim($user->getId(), $task->getId(), '2026-11-05 18:00');

        self::assertSame(TaskStatus::Pending, $tu->getStatus());
        self::assertSame('2026-W45', $tu->getWeekKey());
        self::assertSame('Europe/Vilnius', $tu->getUserTimezone());
        self::assertSame('2026-11-05 16:00:00', $tu->getDeadlineAt()?->format('Y-m-d H:i:s'));
        self::assertSame($task->getId().':'.$user->getId(), $tu->getActiveKey());
    }

    public function testWeekKeyUsesLocalCalendarNotUtc(): void
    {
        // Sunday 23:30 UTC is already Monday (next week) in Vilnius
        $this->clock->modify('2026-11-08 23:30:00 UTC');
        $tu = $this->workflow->claim($this->makeUser('Europe/Vilnius')->getId(), $this->makeTask()->getId());
        self::assertSame('2026-W46', $tu->getWeekKey());

        $tu2 = $this->workflow->claim($this->makeUser('UTC')->getId(), $this->makeTask()->getId());
        self::assertSame('2026-W45', $tu2->getWeekKey());
    }

    public function testClaimingTwiceIsRejectedButAfterCancelItIsAllowed(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask();
        $first = $this->workflow->claim($user->getId(), $task->getId());

        try {
            $this->workflow->claim($user->getId(), $task->getId());
            self::fail('expected TaskAlreadyClaimed');
        } catch (TaskAlreadyClaimed) {
        }

        $this->workflow->cancel($user->getId(), $first->getId());
        self::assertNull($first->getActiveKey());
        $second = $this->workflow->claim($user->getId(), $task->getId());
        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testTwoUsersMayClaimReusableButNotOneOff(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $reusable = $this->makeTask(kind: RecurrenceKind::Reusable);
        $this->workflow->claim($a->getId(), $reusable->getId());
        $this->workflow->claim($b->getId(), $reusable->getId());

        $oneOff = $this->makeTask(kind: RecurrenceKind::OneOff);
        $this->workflow->claim($a->getId(), $oneOff->getId());
        $this->expectException(TaskAlreadyClaimed::class);
        $this->workflow->claim($b->getId(), $oneOff->getId());
    }

    public function testDatabaseRefusesTwoOpenClaimsForSameTaskAndUser(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask();
        $now = $this->clock->now();
        $this->em()->persist(new TaskUser($task, $user, '2026-W45', 'UTC', \DateTimeImmutable::createFromInterface($now)));
        $this->em()->flush();
        $this->em()->persist(new TaskUser($task, $user, '2026-W45', 'UTC', \DateTimeImmutable::createFromInterface($now)));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testUnknownInactiveAndInactiveTypeTasks(): void
    {
        $user = $this->makeUser();
        try {
            $this->workflow->claim($user->getId(), 999999);
            self::fail('expected TaskNotFound');
        } catch (TaskNotFound) {
        }

        $task = $this->makeTask();
        $task->setIsActive(false);
        $this->em()->flush();
        try {
            $this->workflow->claim($user->getId(), $task->getId());
            self::fail('expected TaskNotAvailable');
        } catch (TaskNotAvailable) {
        }

        $task2 = $this->makeTask();
        $task2->getType()?->setIsActive(false);
        $this->em()->flush();
        $this->expectException(TaskNotAvailable::class);
        $this->workflow->claim($user->getId(), $task2->getId());
    }

    public function testDisabledUserCannotClaim(): void
    {
        $user = $this->makeUser();
        $user->setIsActive(false);
        $this->em()->flush();
        $this->expectException(ForbiddenAction::class);
        $this->workflow->claim($user->getId(), $this->makeTask()->getId());
    }

    public function testStartCompleteAwardsPoints(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask(priority: TaskPriority::High, reward: 10);
        $tu = $this->workflow->claim($user->getId(), $task->getId());

        $this->workflow->start($user->getId(), $tu->getId());
        self::assertSame(TaskStatus::InProgress, $tu->getStatus());
        self::assertNotNull($tu->getStartedAt());

        $this->clock->sleep(600);
        $this->workflow->complete($user->getId(), $tu->getId());
        self::assertSame(TaskStatus::Completed, $tu->getStatus());
        self::assertSame(20, $tu->getPointsGained());
        self::assertSame('2026-11-04 10:10:00', $tu->getCompletedAt()?->format('Y-m-d H:i:s'));
        self::assertNull($tu->getActiveKey());
        self::assertTrue($task->isActive(), 'reusable tasks stay available');
    }

    public function testOneOffTaskIsDeactivatedOnCompletion(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask(kind: RecurrenceKind::OneOff);
        $tu = $this->workflow->claim($user->getId(), $task->getId());
        $this->workflow->start($user->getId(), $tu->getId());
        $this->workflow->complete($user->getId(), $tu->getId());

        $this->em()->refresh($task);
        self::assertFalse($task->isActive());
    }

    public function testInvalidTransitions(): void
    {
        $user = $this->makeUser();
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId());

        try { // pending -> completed must go through in_progress
            $this->workflow->complete($user->getId(), $tu->getId());
            self::fail('expected InvalidStatusTransition');
        } catch (InvalidStatusTransition) {
        }

        $this->workflow->start($user->getId(), $tu->getId());
        try { // double start
            $this->workflow->start($user->getId(), $tu->getId());
            self::fail('expected InvalidStatusTransition');
        } catch (InvalidStatusTransition) {
        }

        $this->workflow->complete($user->getId(), $tu->getId());
        foreach (['start', 'complete', 'cancel'] as $method) { // terminal state is final
            try {
                $this->workflow->$method($user->getId(), $tu->getId());
                self::fail("expected InvalidStatusTransition for $method");
            } catch (InvalidStatusTransition) {
            }
        }
        self::assertSame(15, $tu->getPointsGained(), 'points are not awarded twice');
    }

    public function testForeignTaskUserIsForbiddenForEveryAction(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $tu = $this->workflow->claim($owner->getId(), $this->makeTask()->getId());

        foreach (['start', 'complete', 'cancel'] as $method) {
            try {
                $this->workflow->$method($other->getId(), $tu->getId());
                self::fail("expected ForbiddenAction for $method");
            } catch (ForbiddenAction) {
            }
        }
        self::assertSame(TaskStatus::Pending, $tu->getStatus());
    }

    public function testAdminMayCancelSomeoneElsesClaim(): void
    {
        $tu = $this->workflow->claim($this->makeUser()->getId(), $this->makeTask()->getId());
        $admin = $this->makeUser(roles: ['ROLE_ADMIN']);
        $this->workflow->cancel($admin->getId(), $tu->getId());
        self::assertSame(TaskStatus::Cancelled, $tu->getStatus());
    }

    public function testUnknownTaskUser(): void
    {
        $this->expectException(TaskNotFound::class);
        $this->workflow->start($this->makeUser()->getId(), 999999);
    }

    public function testDeactivatedTaskCannotBeStartedButCanBeCompletedIfAlreadyStarted(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask();
        $tu = $this->workflow->claim($user->getId(), $task->getId());
        $task->setIsActive(false);
        $this->em()->flush();

        try {
            $this->workflow->start($user->getId(), $tu->getId());
            self::fail('expected TaskNotAvailable');
        } catch (TaskNotAvailable) {
        }

        $task->setIsActive(true);
        $this->workflow->start($user->getId(), $tu->getId());
        $task->setIsActive(false);
        $this->em()->flush();
        $this->workflow->complete($user->getId(), $tu->getId()); // do not punish the user for an admin edit
        self::assertSame(TaskStatus::Completed, $tu->getStatus());
    }

    public function testDeadlineValidation(): void
    {
        $user = $this->makeUser('America/New_York');
        $task = $this->makeTask();
        foreach (['garbage', '2026-11-04 04:00' /* = 09:00 UTC, past */, '2030-01-01 10:00' /* > 1 year */] as $bad) {
            try {
                $this->workflow->claim($user->getId(), $task->getId(), $bad);
                self::fail("expected InvalidDeadline for $bad");
            } catch (InvalidDeadline) {
            }
        }
        // nothing was persisted by the failed attempts
        self::assertCount(0, $this->em()->getRepository(TaskUser::class)->findBy(['user' => $user]));
    }

    public function testDeadlineAcrossSpringForwardGap(): void
    {
        $this->clock->modify('2026-03-05 10:00:00 UTC');
        $user = $this->makeUser('America/New_York');
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId(), '2026-03-08 02:30'); // does not exist locally
        self::assertSame('2026-03-08 07:30:00', $tu->getDeadlineAt()?->format('Y-m-d H:i:s'));
    }

    public function testParentRules(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $parent = $this->workflow->claim($user->getId(), $this->makeTask(title: 'parent')->getId());
        $foreign = $this->workflow->claim($other->getId(), $this->makeTask(title: 'foreign')->getId());

        $child = $this->workflow->claim($user->getId(), $this->makeTask(title: 'child')->getId(), null, $parent->getId());
        self::assertSame($parent->getId(), $child->getParent()?->getId());

        foreach ([$foreign->getId() => 'someone elses', 999999 => 'missing'] as $id => $why) {
            try {
                $this->workflow->claim($user->getId(), $this->makeTask(title: 'x'.$id)->getId(), null, $id);
                self::fail("expected InvalidParentTask ($why)");
            } catch (InvalidParentTask) {
            }
        }

        $this->workflow->cancel($user->getId(), $parent->getId());
        $this->expectException(InvalidParentTask::class); // cancelled parent
        $this->workflow->claim($user->getId(), $this->makeTask(title: 'late')->getId(), null, $parent->getId());
    }

    public function testCyclicParentChainIsDetected(): void
    {
        $user = $this->makeUser();
        $a = $this->workflow->claim($user->getId(), $this->makeTask(title: 'a')->getId());
        $b = $this->workflow->claim($user->getId(), $this->makeTask(title: 'b')->getId(), null, $a->getId());
        $a->setParent($b); // corrupt data: a <-> b
        $this->em()->flush();

        $this->expectException(InvalidParentTask::class);
        $this->workflow->claim($user->getId(), $this->makeTask(title: 'c')->getId(), null, $b->getId());
    }

    public function testParentChainTooDeep(): void
    {
        $user = $this->makeUser();
        $parentId = null;
        for ($i = 0; $i <= TaskWorkflowService::MAX_PARENT_DEPTH; ++$i) {
            $parentId = $this->workflow->claim($user->getId(), $this->makeTask(title: "t$i")->getId(), null, $parentId)->getId();
        }
        $this->expectException(InvalidParentTask::class);
        $this->workflow->claim($user->getId(), $this->makeTask(title: 'too deep')->getId(), null, $parentId);
    }

    public function testCompletingAfterDeadlineExpiresAndRefuses(): void
    {
        $user = $this->makeUser();
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId(), '2026-11-04 12:00');
        $this->workflow->start($user->getId(), $tu->getId());
        $this->clock->modify('2026-11-04 13:00:00 UTC');

        try {
            $this->workflow->complete($user->getId(), $tu->getId());
            self::fail('expected InvalidStatusTransition');
        } catch (InvalidStatusTransition) {
        }
        $this->em()->refresh($tu);
        self::assertSame(TaskStatus::Expired, $tu->getStatus(), 'the expiry is persisted, not rolled back');
        self::assertSame(0, $tu->getPointsGained());
    }

    public function testClockSkewIsClampedWithinToleranceAndRejectedBeyond(): void
    {
        $user = $this->makeUser();
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId());
        $this->workflow->start($user->getId(), $tu->getId());

        $this->clock->modify('2026-11-04 09:58:00 UTC'); // 2 min behind start: tolerated
        $this->workflow->complete($user->getId(), $tu->getId());
        self::assertEquals($tu->getStartedAt(), $tu->getCompletedAt());

        $tu2 = $this->workflow->claim($user->getId(), $this->makeTask(title: 'other')->getId());
        $this->clock->modify('2026-11-04 10:00:00 UTC');
        $this->workflow->start($user->getId(), $tu2->getId());
        $this->clock->modify('2026-11-04 09:00:00 UTC'); // an hour behind: rejected
        $this->expectException(InvalidStatusTransition::class);
        $this->workflow->complete($user->getId(), $tu2->getId());
    }

    public function testExpireIsIdempotent(): void
    {
        $user = $this->makeUser();
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId(), '2026-11-04 12:00');

        self::assertFalse($this->workflow->expire($tu->getId()), 'not overdue yet');
        $this->clock->modify('2026-11-04 13:00:00 UTC');
        self::assertTrue($this->workflow->expire($tu->getId()));
        self::assertFalse($this->workflow->expire($tu->getId()), 'second run is a no-op');
        self::assertFalse($this->workflow->expire(999999), 'missing row is a no-op');
        self::assertSame(TaskStatus::Expired, $tu->getStatus());
    }
}
