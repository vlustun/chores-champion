<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /** Sorting weight, higher = more urgent. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    /** Points multiplier in tenths (integer maths, no float drift). */
    public function multiplierTenths(): int
    {
        return match ($this) {
            self::Low => 10,
            self::Medium => 15,
            self::High => 20,
            self::Urgent => 30,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
