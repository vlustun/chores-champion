<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Cancelled, self::Expired],
            self::InProgress => [self::Completed, self::Cancelled, self::Expired],
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    /** Open = still occupies the user's "one active claim per task" slot. */
    public function isOpen(): bool
    {
        return self::Pending === $this || self::InProgress === $this;
    }

    public function isTerminal(): bool
    {
        return !$this->isOpen();
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Pending->value, self::InProgress->value];
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
