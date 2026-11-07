<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\ExpireOverdueTasksCommand;
use App\Entity\TaskUser;
use App\Enum\TaskStatus;
use App\Metrics\MetricsCollector;
use App\Repository\TaskUserRepository;
use App\Service\Points\PointsCalculator;
use App\Service\TaskWorkflowService;
use App\Service\Time\TimezoneResolver;
use App\Service\Time\UserClock;
use App\Tests\Support\Fixtures;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ExpireOverdueTasksCommandTest extends KernelTestCase
{
    use Fixtures;

    private MockClock $clock;
    private TaskWorkflowService $workflow;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-11-04 10:00:00 UTC');
        $userClock = new UserClock($this->clock, new TimezoneResolver($this->em(), new NullLogger(), 'UTC'));
        $this->workflow = new TaskWorkflowService(static::getContainer()->get('doctrine'), $userClock, new PointsCalculator(), new MetricsCollector(new \Prometheus\Storage\InMemory()), new NullLogger());
        $this->tester = new CommandTester(new ExpireOverdueTasksCommand(static::getContainer()->get(TaskUserRepository::class), $this->workflow, $userClock, new NullLogger()));
    }

    public function testExpiresOnlyOverdueOpenClaimsAndIsIdempotent(): void
    {
        $user = $this->makeUser('America/New_York');
        $overdue = $this->workflow->claim($user->getId(), $this->makeTask(title: 'a')->getId(), '2026-11-04 06:00'); // 11:00 UTC
        $started = $this->workflow->claim($user->getId(), $this->makeTask(title: 'b')->getId(), '2026-11-04 06:30');
        $this->workflow->start($user->getId(), $started->getId());
        $later = $this->workflow->claim($user->getId(), $this->makeTask(title: 'c')->getId(), '2026-11-05 06:00');
        $noDeadline = $this->workflow->claim($user->getId(), $this->makeTask(title: 'd')->getId());
        $done = $this->workflow->claim($user->getId(), $this->makeTask(title: 'e')->getId(), '2026-11-04 06:10');
        $this->workflow->start($user->getId(), $done->getId());
        $this->workflow->complete($user->getId(), $done->getId());

        $this->clock->modify('2026-11-04 12:00:00 UTC'); // 07:00 in New York: a, b are late

        self::assertSame(0, $this->tester->execute([]));
        self::assertStringContainsString('2 expired', $this->tester->getDisplay());

        $status = fn (TaskUser $t) => $this->em()->find(TaskUser::class, $t->getId())->getStatus();
        self::assertSame(TaskStatus::Expired, $status($overdue));
        self::assertSame(TaskStatus::Expired, $status($started));
        self::assertSame(TaskStatus::Pending, $status($later));
        self::assertSame(TaskStatus::Pending, $status($noDeadline));
        self::assertSame(TaskStatus::Completed, $status($done));

        // second run: nothing left
        self::assertSame(0, $this->tester->execute([]));
        self::assertStringContainsString('0 expired', $this->tester->getDisplay());
    }

    public function testExpiredClaimFreesTheSlotForAFreshClaim(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask();
        $first = $this->workflow->claim($user->getId(), $task->getId(), '2026-11-04 11:00');
        $this->clock->modify('2026-11-04 12:00:00 UTC');
        $this->tester->execute([]);

        $second = $this->workflow->claim($user->getId(), $task->getId());
        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testDryRunChangesNothing(): void
    {
        $user = $this->makeUser();
        $tu = $this->workflow->claim($user->getId(), $this->makeTask()->getId(), '2026-11-04 11:00');
        $this->clock->modify('2026-11-04 12:00:00 UTC');

        $this->tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('[dry-run] 1 expired', $this->tester->getDisplay());
        self::assertSame(TaskStatus::Pending, $this->em()->find(TaskUser::class, $tu->getId())->getStatus());
    }

    public function testBatchOptionIsValidatedAndSmallBatchesStillFinishTheJob(): void
    {
        self::assertSame(2, $this->tester->execute(['--batch' => '0']));

        $user = $this->makeUser();
        for ($i = 0; $i < 5; ++$i) {
            $this->workflow->claim($user->getId(), $this->makeTask(title: "t$i")->getId(), '2026-11-04 11:00');
        }
        $this->clock->modify('2026-11-04 12:00:00 UTC');
        self::assertSame(0, $this->tester->execute(['--batch' => '2']));
        self::assertStringContainsString('5 expired', $this->tester->getDisplay());
    }
}
