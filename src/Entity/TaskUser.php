<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskStatus;
use App\Repository\TaskUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskUserRepository::class)]
#[ORM\Table(name: 'task_user')]
#[ORM\Index(name: 'idx_tu_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_tu_week', columns: ['week_key'])]
#[ORM\Index(name: 'idx_tu_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_tu_deadline', columns: ['status', 'deadline_at'])]
#[ORM\UniqueConstraint(name: 'uniq_tu_active_key', columns: ['active_key'])]
#[ORM\HasLifecycleCallbacks]
class TaskUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'RESTRICT')]
    private Task $task;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 16, enumType: TaskStatus::class)]
    private TaskStatus $status = TaskStatus::Pending;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', precision: 6, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', precision: 6, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_task_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\Column(name: 'points_gained', options: ['default' => 0])]
    private int $pointsGained = 0;

    #[ORM\Column(name: 'deadline_at', type: 'datetime_immutable', precision: 6, nullable: true)]
    private ?\DateTimeImmutable $deadlineAt = null;

    /** Snapshot of the user's IANA timezone at claim time (history stays stable). */
    #[ORM\Column(name: 'user_timezone', length: 64)]
    private string $userTimezone = 'UTC';

    /** ISO week in the user's local calendar at claim time, e.g. 2026-W45. */
    #[ORM\Column(name: 'week_key', length: 10)]
    private string $weekKey;

    /**
     * "taskId:userId" while open, NULL once terminal. MySQL has no partial
     * unique indexes, but unique indexes ignore NULLs, which gives us
     * "at most one open claim per (task, user)" enforced by the database.
     */
    #[ORM\Column(name: 'active_key', length: 40, nullable: true)]
    private ?string $activeKey = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    /** Optimistic lock. */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(Task $task, User $user, string $weekKey, string $userTimezone, \DateTimeImmutable $now)
    {
        $this->task = $task;
        $this->user = $user;
        $this->weekKey = $weekKey;
        $this->userTimezone = $userTimezone;
        $this->createdAt = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->syncActiveKey();
    }

    #[ORM\PrePersist]
    public function syncActiveKey(): void
    {
        $taskId = $this->task->getId();
        $userId = $this->user->getId();
        // ids are null before the first flush of new parents; keep value computed lazily then
        $this->activeKey = $this->status->isOpen() && null !== $taskId && null !== $userId
            ? $taskId.':'.$userId
            : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /** Raw setter - transitions must go through TaskWorkflowService. */
    public function setStatus(TaskStatus $status): static
    {
        $this->status = $status;
        $this->syncActiveKey();

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getPointsGained(): int
    {
        return $this->pointsGained;
    }

    public function setPointsGained(int $points): static
    {
        $this->pointsGained = $points;

        return $this;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(?\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;

        return $this;
    }

    public function getUserTimezone(): string
    {
        return $this->userTimezone;
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getActiveKey(): ?string
    {
        return $this->activeKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
