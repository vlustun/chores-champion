<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurrenceKind: string
{
    /** Can be completed once; afterwards the task is deactivated. */
    case OneOff = 'one_off';
    /** Can be claimed again and again (once per user at a time). */
    case Reusable = 'reusable';
    /** Scheduled repetition; mechanics are deferred (see docs/DECISIONS.md). */
    case Recurring = 'recurring';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
