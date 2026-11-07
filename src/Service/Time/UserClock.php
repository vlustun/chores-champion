<?php

declare(strict_types=1);

namespace App\Service\Time;

use App\Exception\InvalidDeadline;
use Psr\Clock\ClockInterface;

/**
 * All timezone maths lives here. Inputs/outputs of instants are always
 * DateTimeImmutable; "UTC" results are explicitly in the UTC zone.
 *
 * DST policy for local wall-clock -> UTC conversion:
 *  - nonexistent time (spring-forward gap): moved forward by the gap length (02:30 -> 03:30)
 *  - ambiguous time (fall-back overlap): the FIRST occurrence (before the clocks go back) is used
 */
final class UserClock
{
    private readonly \DateTimeZone $utc;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly TimezoneResolver $resolver,
    ) {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function nowUtc(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($this->utc);
    }

    /** Invalid identifiers fall back to the app default instead of failing the page. */
    public function zone(?string $tz): \DateTimeZone
    {
        return new \DateTimeZone($this->resolver->resolve($tz));
    }

    public function toLocal(\DateTimeInterface $instant, ?string $tz): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone($this->zone($tz));
    }

    public function toUtc(\DateTimeInterface $instant): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone($this->utc);
    }

    /** Monday 00:00 local (first valid instant of that day), returned in UTC. */
    public function weekStart(\DateTimeInterface $instant, ?string $tz): \DateTimeImmutable
    {
        $zone = $this->zone($tz);
        $local = \DateTimeImmutable::createFromInterface($instant)->setTimezone($zone);
        $daysSinceMonday = (int) $local->format('N') - 1;
        // pure calendar arithmetic on the date part in UTC => immune to DST shifts
        $mondayDate = (new \DateTimeImmutable($local->format('Y-m-d'), $this->utc))
            ->modify(\sprintf('-%d days', $daysSinceMonday))
            ->format('Y-m-d');

        return (new \DateTimeImmutable($mondayDate.' 00:00:00', $zone))->setTimezone($this->utc);
    }

    /** Exclusive end of the week = next Monday 00:00 local, in UTC. 167 or 169 hours around DST. */
    public function weekEnd(\DateTimeInterface $instant, ?string $tz): \DateTimeImmutable
    {
        $zone = $this->zone($tz);
        $start = $this->weekStart($instant, $tz)->setTimezone($zone);
        $nextMonday = (new \DateTimeImmutable($start->format('Y-m-d'), $this->utc))->modify('+7 days')->format('Y-m-d');

        return (new \DateTimeImmutable($nextMonday.' 00:00:00', $zone))->setTimezone($this->utc);
    }

    /** ISO-8601 week of the user's local calendar, e.g. "2026-W45" (year-end safe: 2024-12-30 => 2025-W01). */
    public function weekKey(\DateTimeInterface $instant, ?string $tz): string
    {
        return $this->toLocal($instant, $tz)->format('o-\WW');
    }

    /** Monday 00:00 local of an ISO week key ("2026-W45"), in UTC. Null when the key is malformed. */
    public function weekStartFromKey(string $weekKey, ?string $tz): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4})-W(0[1-9]|[1-4]\d|5[0-3])$/', $weekKey, $m)) {
            return null;
        }
        $monday = $this->nowUtc()->setISODate((int) $m[1], (int) $m[2], 1);
        if ($monday->format('o-\WW') !== $weekKey) {
            return null; // e.g. W53 in a 52-week year
        }

        return (new \DateTimeImmutable($monday->format('Y-m-d').' 00:00:00', $this->zone($tz)))->setTimezone($this->utc);
    }

    /**
     * Convert a local wall-clock string ("2026-03-29 02:30" or "2026-03-29T02:30")
     * in $tz to a UTC instant. Throws InvalidDeadline when unparsable.
     */
    public function localToUtc(string $wallClock, ?string $tz): \DateTimeImmutable
    {
        $wallClock = trim($wallClock);
        $zone = $this->zone($tz);
        $parsed = null;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat('!'.$format, $wallClock, $this->utc);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $candidate && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))) {
                $parsed = $candidate;
                break;
            }
        }
        if (null === $parsed) {
            throw new InvalidDeadline('Deadline must look like YYYY-MM-DD HH:MM.', ['input' => mb_substr($wallClock, 0, 40)]);
        }

        // Re-interpret the same wall-clock fields in the target zone (PHP: gap => forward, overlap => first).
        $local = new \DateTimeImmutable($parsed->format('Y-m-d H:i:s'), $zone);

        return $local->setTimezone($this->utc);
    }

    /** True when the wall-clock time does not exist in $tz (skipped by a spring-forward). */
    public function isNonexistentLocalTime(string $wallClock, ?string $tz): bool
    {
        $parsed = $this->parseWall($wallClock);
        $local = new \DateTimeImmutable($parsed->format('Y-m-d H:i:s'), $this->zone($tz));

        return $local->format('Y-m-d H:i:s') !== $parsed->format('Y-m-d H:i:s');
    }

    /** True when the wall-clock time occurs twice in $tz (repeated by a fall-back). */
    public function isAmbiguousLocalTime(string $wallClock, ?string $tz): bool
    {
        $zone = $this->zone($tz);
        $wall = $this->parseWall($wallClock)->getTimestamp(); // wall clock read as if UTC
        $approx = $wall; // offsets are < 1 day, so transitions near $wall are within +-1 day
        $transitions = $zone->getTransitions($approx - 2 * 86400, $approx + 2 * 86400);
        $prevOffset = $transitions[0]['offset'] ?? 0;
        foreach (\array_slice($transitions, 1) as $t) {
            $newOffset = $t['offset'];
            if ($newOffset < $prevOffset) {
                // wall clock repeated in [T+new, T+prev)
                if ($wall >= $t['ts'] + $newOffset && $wall < $t['ts'] + $prevOffset) {
                    return true;
                }
            }
            $prevOffset = $newOffset;
        }

        return false;
    }

    private function parseWall(string $wallClock): \DateTimeImmutable
    {
        $wallClock = trim($wallClock);
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            $c = \DateTimeImmutable::createFromFormat('!'.$format, $wallClock, $this->utc);
            if (false !== $c) {
                return $c;
            }
        }

        throw new InvalidDeadline('Deadline must look like YYYY-MM-DD HH:MM.');
    }
}
