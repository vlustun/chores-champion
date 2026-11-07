<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Task;

/** Read model for a chore, shared by the future REST API and any JSON consumer. No entity leakage. */
final readonly class TaskView implements \JsonSerializable
{
    public function __construct(
        public ?int $id,
        public string $title,
        public ?string $description,
        public ?string $type,
        public string $priority,
        public ?int $rewardValue,
        public string $recurrenceKind,
        public bool $active,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Task $task): self
    {
        return new self(
            $task->getId(),
            $task->getTitle(),
            $task->getDescription(),
            $task->getType()?->getSlug(),
            $task->getPriority()->value,
            $task->getRewardValue(),
            $task->getRecurrenceKind()->value,
            $task->isActive(),
            $task->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'priority' => $this->priority,
            'reward_value' => $this->rewardValue,
            'recurrence_kind' => $this->recurrenceKind,
            'active' => $this->active,
            'created_at' => $this->createdAt,
        ];
    }
}
