<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_active_priority', columns: ['is_active', 'priority'])]
#[ORM\HasLifecycleCallbacks]
class Task
{
    public const MAX_REWARD = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 150)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'type_id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?TaskType $type = null;

    #[ORM\Column(length: 16, enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Medium;

    #[ORM\Column(name: 'reward_value', nullable: true)]
    #[Assert\Range(min: 0, max: self::MAX_REWARD)]
    private ?int $rewardValue = null;

    #[ORM\Column(name: 'recurrence_kind', length: 16, enumType: RecurrenceKind::class)]
    private RecurrenceKind $recurrenceKind = RecurrenceKind::OneOff;

    /**
     * Reserved for later (cadence definition).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'recurrence_config', type: 'json', nullable: true)]
    private ?array $recurrenceConfig = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): ?TaskType
    {
        return $this->type;
    }

    public function setType(?TaskType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function setPriority(TaskPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getRewardValue(): ?int
    {
        return $this->rewardValue;
    }

    public function setRewardValue(?int $rewardValue): static
    {
        $this->rewardValue = $rewardValue;

        return $this;
    }

    public function getRecurrenceKind(): RecurrenceKind
    {
        return $this->recurrenceKind;
    }

    public function setRecurrenceKind(RecurrenceKind $kind): static
    {
        $this->recurrenceKind = $kind;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRecurrenceConfig(): ?array
    {
        return $this->recurrenceConfig;
    }

    /** @param array<string, mixed>|null $config */
    public function setRecurrenceConfig(?array $config): static
    {
        $this->recurrenceConfig = $config;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
