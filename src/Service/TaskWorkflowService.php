<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\TaskUser;
use App\Entity\User;
use App\Enum\RecurrenceKind;
use App\Enum\TaskStatus;
use App\Exception\AppException;
use App\Exception\ForbiddenAction;
use App\Exception\InvalidDeadline;
use App\Exception\InvalidParentTask;
use App\Exception\InvalidStatusTransition;
use App\Exception\TaskAlreadyClaimed;
use App\Exception\TaskNotAvailable;
use App\Exception\TaskNotFound;
use App\Metrics\MetricsCollector;
use App\Service\Points\PointsCalculatorInterface;
use App\Service\Time\UserClock;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Claim -> start -> complete (or cancel / expire) lifecycle of a task_user.
 *
 * Concurrency: every mutating operation runs in a transaction and takes a
 * pessimistic row lock (SELECT ... FOR UPDATE) on the task (claim) or the
 * task_user (everything else). The unique active_key column is the DB-level
 * backstop. Deadlocks / lock-wait timeouts are retried (fresh EntityManager).
 *
 * Operations take ids rather than entities so a retry can reload everything
 * after Doctrine closed the EntityManager.
 */
class TaskWorkflowService
{
    public const MAX_ATTEMPTS = 3;
    /** Completing "before" start by less than this is treated as clock skew and clamped. */
    public const CLOCK_SKEW_TOLERANCE_SECONDS = 300;
    public const MAX_DEADLINE_HORIZON = 'P1Y';
    public const MAX_PARENT_DEPTH = 10;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly UserClock $clock,
        private readonly PointsCalculatorInterface $points,
        private readonly MetricsCollector $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $deadlineLocal wall-clock in the user's timezone, "YYYY-MM-DD HH:MM"
     */
    public function claim(int $userId, int $taskId, ?string $deadlineLocal = null, ?int $parentTaskUserId = null): TaskUser
    {
        try {
            /** @var TaskUser $tu */
            $tu = $this->transactional(function (EntityManagerInterface $em) use ($userId, $taskId, $deadlineLocal, $parentTaskUserId): TaskUser {
                $user = $this->loadUser($em, $userId);
                if (!$user->isActive()) {
                    throw new ForbiddenAction('Your account is disabled.');
                }
                $task = $em->find(Task::class, $taskId, LockMode::PESSIMISTIC_WRITE) ?? throw new TaskNotFound();
                if (!$task->isActive() || !$task->getType()?->isActive()) {
                    throw new TaskNotAvailable('This task is no longer available.', ['task_id' => $taskId]);
                }

                $repo = $em->getRepository(TaskUser::class);
                if (null !== $repo->findOpenByTaskAndUser($task, $user)) {
                    throw new TaskAlreadyClaimed('You already have this task open.', ['task_id' => $taskId]);
                }
                if (RecurrenceKind::OneOff === $task->getRecurrenceKind() && $repo->hasOpenClaim($task)) {
                    throw new TaskAlreadyClaimed('Someone else already picked up this one-off task.', ['task_id' => $taskId]);
                }

                $now = $this->clock->nowUtc();
                $parent = null !== $parentTaskUserId ? $this->resolveParent($em, $user, $parentTaskUserId) : null;
                $deadline = $this->resolveDeadline($deadlineLocal, $user, $now);

                $tu = new TaskUser(
                    $task,
                    $user,
                    $this->clock->weekKey($now, $user->getTimezone()),
                    $user->getTimezone(),
                    $now,
                );
                $tu->setParent($parent)->setDeadlineAt($deadline);
                $em->persist($tu);
                $em->flush();

                return $tu;
            });
        } catch (UniqueConstraintViolationException $e) {
            // lost a race the row lock did not cover (e.g. one lock-free path): same outcome for the user
            throw new TaskAlreadyClaimed('You already have this task open.', ['task_id' => $taskId], $e);
        }

        $this->metrics->taskClaimed();
        $this->logger->info('Task claimed', ['task_user_id' => $tu->getId(), 'task_id' => $taskId, 'user_id' => $userId]);

        return $tu;
    }

    public function start(int $userId, int $taskUserId): TaskUser
    {
        /** @var TaskUser $tu */
        $tu = $this->transactional(function (EntityManagerInterface $em) use ($userId, $taskUserId): TaskUser {
            $tu = $this->lockOwned($em, $userId, $taskUserId);
            $this->assertTransition($tu, TaskStatus::InProgress);
            if (!$tu->getTask()->isActive()) {
                throw new TaskNotAvailable('This task was deactivated and can no longer be started.', ['task_user_id' => $taskUserId]);
            }
            $tu->setStatus(TaskStatus::InProgress)->setStartedAt($this->clock->nowUtc());
            $em->flush();

            return $tu;
        });
        $this->metrics->taskTransition('in_progress');
        $this->logger->info('Task started', ['task_user_id' => $taskUserId, 'user_id' => $userId]);

        return $tu;
    }

    public function complete(int $userId, int $taskUserId): TaskUser
    {
        /** @var array{0: TaskUser, 1: bool} $result */
        $result = $this->transactional(function (EntityManagerInterface $em) use ($userId, $taskUserId): array {
            $tu = $this->lockOwned($em, $userId, $taskUserId);
            $this->assertTransition($tu, TaskStatus::Completed);
            $now = $this->clock->nowUtc();

            // Past the deadline but the expiry cron has not run yet: expire now (persisted), then refuse.
            if (null !== $tu->getDeadlineAt() && $tu->getDeadlineAt() < $now) {
                $tu->setStatus(TaskStatus::Expired);
                $em->flush();

                return [$tu, true];
            }

            $started = $tu->getStartedAt() ?? throw new InvalidStatusTransition('Task has no start time.', ['task_user_id' => $taskUserId]);
            if ($now < $started) {
                $skewSeconds = $started->getTimestamp() - $now->getTimestamp();
                if ($skewSeconds > self::CLOCK_SKEW_TOLERANCE_SECONDS) {
                    throw new InvalidStatusTransition('Completion time is before the start time.', ['task_user_id' => $taskUserId, 'skew_s' => $skewSeconds]);
                }
                $this->logger->warning('Clock skew clamped on completion', ['task_user_id' => $taskUserId, 'skew_s' => $skewSeconds]);
                $now = $started;
            }

            $tu->setStatus(TaskStatus::Completed)
                ->setCompletedAt($now)
                ->setPointsGained($this->points->calculate($tu, $now));

            $task = $tu->getTask();
            if (RecurrenceKind::OneOff === $task->getRecurrenceKind()) {
                $task->setIsActive(false);
            }
            $em->flush();

            return [$tu, false];
        });

        [$tu, $expired] = $result;
        if ($expired) {
            $this->metrics->taskTransition('expired');
            $this->logger->notice('Completion refused: deadline passed, claim expired', ['task_user_id' => $taskUserId, 'user_id' => $userId]);
            throw new InvalidStatusTransition('The deadline has passed; this task has expired.', ['task_user_id' => $taskUserId]);
        }

        $this->metrics->taskTransition('completed');
        $this->metrics->taskCompleted($tu->getPointsGained());
        $this->logger->info('Task completed', ['task_user_id' => $taskUserId, 'user_id' => $userId, 'points' => $tu->getPointsGained()]);

        return $tu;
    }

    /** Owner or an admin may cancel. */
    public function cancel(int $actorId, int $taskUserId): TaskUser
    {
        /** @var TaskUser $tu */
        $tu = $this->transactional(function (EntityManagerInterface $em) use ($actorId, $taskUserId): TaskUser {
            $actor = $this->loadUser($em, $actorId);
            $tu = $em->getRepository(TaskUser::class)->findForUpdate($taskUserId) ?? throw new TaskNotFound('Task assignment not found.');
            if ($tu->getUser()->getId() !== $actor->getId() && !$actor->isAdmin()) {
                throw new ForbiddenAction('This is not your task.', ['task_user_id' => $taskUserId, 'actor_id' => $actorId]);
            }
            $this->assertTransition($tu, TaskStatus::Cancelled);
            $tu->setStatus(TaskStatus::Cancelled);
            $em->flush();

            return $tu;
        });
        $this->metrics->taskTransition('cancelled');
        $this->logger->info('Task cancelled', ['task_user_id' => $taskUserId, 'actor_id' => $actorId]);

        return $tu;
    }

    /**
     * System operation (cron). Idempotent: returns false when already terminal or not yet overdue.
     */
    public function expire(int $taskUserId): bool
    {
        /** @var bool $done */
        $done = $this->transactional(function (EntityManagerInterface $em) use ($taskUserId): bool {
            $tu = $em->getRepository(TaskUser::class)->findForUpdate($taskUserId);
            if (null === $tu || !$tu->getStatus()->isOpen()) {
                return false;
            }
            $deadline = $tu->getDeadlineAt();
            if (null === $deadline || $deadline >= $this->clock->nowUtc()) {
                return false;
            }
            $tu->setStatus(TaskStatus::Expired);
            $em->flush();

            return true;
        });
        if ($done) {
            $this->metrics->taskTransition('expired');
        }

        return $done;
    }

    // ---------------------------------------------------------------------

    private function assertTransition(TaskUser $tu, TaskStatus $target): void
    {
        if (!$tu->getStatus()->canTransitionTo($target)) {
            throw new InvalidStatusTransition(\sprintf('Cannot go from "%s" to "%s".', $tu->getStatus()->label(), $target->label()), ['task_user_id' => $tu->getId(), 'from' => $tu->getStatus()->value, 'to' => $target->value]);
        }
    }

    private function loadUser(EntityManagerInterface $em, int $userId): User
    {
        return $em->find(User::class, $userId) ?? throw new ForbiddenAction('Unknown user.', ['user_id' => $userId]);
    }

    private function lockOwned(EntityManagerInterface $em, int $userId, int $taskUserId): TaskUser
    {
        $tu = $em->getRepository(TaskUser::class)->findForUpdate($taskUserId) ?? throw new TaskNotFound('Task assignment not found.');
        if ($tu->getUser()->getId() !== $userId) {
            throw new ForbiddenAction('This is not your task.', ['task_user_id' => $taskUserId, 'actor_id' => $userId]);
        }

        return $tu;
    }

    private function resolveParent(EntityManagerInterface $em, User $user, int $parentId): TaskUser
    {
        $parent = $em->find(TaskUser::class, $parentId) ?? throw new InvalidParentTask('Parent task not found.', ['parent_id' => $parentId]);
        if ($parent->getUser()->getId() !== $user->getId()) {
            throw new InvalidParentTask('Parent task belongs to someone else.', ['parent_id' => $parentId]);
        }
        if (TaskStatus::Cancelled === $parent->getStatus() || TaskStatus::Expired === $parent->getStatus()) {
            throw new InvalidParentTask('Parent task is cancelled or expired.', ['parent_id' => $parentId]);
        }

        // Walk up the chain: guards against (pre-existing) cycles and absurd depth.
        $seen = [];
        $depth = 1;
        for ($node = $parent; null !== $node; $node = $node->getParent(), ++$depth) {
            $id = $node->getId();
            if (isset($seen[$id]) || $depth > self::MAX_PARENT_DEPTH) {
                throw new InvalidParentTask('Parent chain is cyclic or too deep.', ['parent_id' => $parentId]);
            }
            $seen[$id] = true;
        }

        return $parent;
    }

    private function resolveDeadline(?string $deadlineLocal, User $user, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (null === $deadlineLocal || '' === trim($deadlineLocal)) {
            return null;
        }
        $deadline = $this->clock->localToUtc($deadlineLocal, $user->getTimezone());
        if ($deadline <= $now) {
            throw new InvalidDeadline('The deadline must be in the future.');
        }
        if ($deadline > $now->add(new \DateInterval(self::MAX_DEADLINE_HORIZON))) {
            throw new InvalidDeadline('The deadline is too far in the future (max 1 year).');
        }

        return $deadline;
    }

    /**
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            $em = $this->registry->getManager();
            if (!$em instanceof EntityManagerInterface) {
                throw new \LogicException('Default manager is not an ORM EntityManager.');
            }
            if (!$em->isOpen()) {
                $em = $this->registry->resetManager();
                \assert($em instanceof EntityManagerInterface);
            }
            try {
                return $this->inTransaction($em, $work);
            } catch (RetryableException $e) {
                $this->logger->warning('Retryable DB error', ['attempt' => $attempt, 'error' => $e::class]);
                $this->registry->resetManager();
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep(random_int(20, 80) * 1000 * $attempt);
            }
        }
    }

    /**
     * Like EntityManager::wrapInTransaction(), except domain exceptions (AppException) roll back
     * but leave the EntityManager open: they are always thrown before anything is mutated, so
     * managed entities stay valid and the caller can keep working (flash message, re-render).
     * Any other failure closes the EntityManager, as Doctrine requires.
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    private function inTransaction(EntityManagerInterface $em, callable $work): mixed
    {
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $result = $work($em);
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $conn->rollBack();
            } catch (\Throwable) {
                // connection already gone (e.g. deadlock victim): the original error is what matters
            }
            if (!$e instanceof AppException) {
                $em->close();
            }

            throw $e;
        }
    }
}
