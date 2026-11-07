<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\InvalidDeadline;
use App\Service\Time\TimezoneResolver;
use App\Service\Time\UserClock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class UserClockTest extends TestCase
{
    private function clock(string $now = '2026-11-07 12:00:00 UTC'): UserClock
    {
        $resolver = new TimezoneResolver($this->createStub(EntityManagerInterface::class), new NullLogger(), 'UTC');

        return new UserClock(new MockClock($now), $resolver);
    }

    private function utc(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
    }

    public function testNowIsUtc(): void
    {
        self::assertSame('UTC', $this->clock('2026-11-07 12:00:00 Europe/Vilnius')->nowUtc()->getTimezone()->getName());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function weekStarts(): iterable
    {
        // instant (UTC), tz, expected start (UTC), expected week key
        yield 'utc mid-week' => ['2026-11-04 10:00:00', 'UTC', '2026-11-02 00:00:00', '2026-W45'];
        yield 'utc sunday last second' => ['2026-11-08 23:59:59', 'UTC', '2026-11-02 00:00:00', '2026-W45'];
        yield 'utc monday first second' => ['2026-11-09 00:00:00', 'UTC', '2026-11-09 00:00:00', '2026-W46'];
        // Sunday 23:30 UTC is already Monday in Vilnius (UTC+2): new week there
        yield 'ahead of utc crosses into monday' => ['2026-11-08 22:30:00', 'Europe/Vilnius', '2026-11-08 22:00:00', '2026-W46'];
        // Monday 03:00 UTC is still Sunday in New York (UTC-5 in Nov): old week there
        yield 'behind utc stays in sunday' => ['2026-11-09 03:00:00', 'America/New_York', '2026-11-02 05:00:00', '2026-W45'];
        yield 'UTC+14 Kiritimati' => ['2026-11-08 10:30:00', 'Pacific/Kiritimati', '2026-11-08 10:00:00', '2026-W46'];
        yield 'UTC-12 Etc' => ['2026-11-09 11:00:00', 'Etc/GMT+12', '2026-11-02 12:00:00', '2026-W45'];
        yield 'half hour offset India' => ['2026-11-04 10:00:00', 'Asia/Kolkata', '2026-11-01 18:30:00', '2026-W45'];
        // DST: week of fall-back in New York (Sun 2026-11-01); Monday 2026-11-02 is EST (UTC-5)
        yield 'after fall back' => ['2026-11-03 12:00:00', 'America/New_York', '2026-11-02 05:00:00', '2026-W45'];
        // Monday before fall-back is still EDT (UTC-4)
        yield 'before fall back' => ['2026-10-28 12:00:00', 'America/New_York', '2026-10-26 04:00:00', '2026-W44'];
        // year end ISO weeks
        yield 'year end belongs to next ISO year' => ['2024-12-31 12:00:00', 'UTC', '2024-12-30 00:00:00', '2025-W01'];
        yield 'jan 1 belongs to previous ISO year' => ['2027-01-01 12:00:00', 'UTC', '2026-12-28 00:00:00', '2026-W53'];
    }

    #[DataProvider('weekStarts')]
    public function testWeekStartAndKey(string $instant, string $tz, string $expectedStart, string $expectedKey): void
    {
        $c = $this->clock();
        $at = $this->utc($instant);
        self::assertSame($expectedStart, $c->weekStart($at, $tz)->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $c->weekStart($at, $tz)->getTimezone()->getName());
        self::assertSame($expectedKey, $c->weekKey($at, $tz));
    }

    public function testWeekLengthAcrossDst(): void
    {
        $c = $this->clock();
        // week containing US fall-back (25 hours on Sunday) is 169h; spring-forward week is 167h
        $fall = $c->weekEnd($this->utc('2026-10-28 12:00:00'), 'America/New_York')->getTimestamp()
            - $c->weekStart($this->utc('2026-10-28 12:00:00'), 'America/New_York')->getTimestamp();
        $spring = $c->weekEnd($this->utc('2026-03-04 12:00:00'), 'America/New_York')->getTimestamp()
            - $c->weekStart($this->utc('2026-03-04 12:00:00'), 'America/New_York')->getTimestamp();
        self::assertSame(169 * 3600, $fall);
        self::assertSame(167 * 3600, $spring);
    }

    public function testWeekEndIsNextWeekStart(): void
    {
        $c = $this->clock();
        $at = $this->utc('2026-11-04 10:00:00');
        self::assertEquals($c->weekStart($this->utc('2026-11-10 10:00:00'), 'Europe/Vilnius'), $c->weekEnd($at, 'Europe/Vilnius'));
    }

    public function testInvalidTimezoneFallsBackToDefault(): void
    {
        $c = $this->clock();
        self::assertSame('UTC', $c->zone('Mars/Olympus')->getName());
        self::assertSame('UTC', $c->zone(null)->getName());
        self::assertSame('2026-W45', $c->weekKey($this->utc('2026-11-04 10:00:00'), '+02:00'));
    }

    public function testLocalToUtcSimple(): void
    {
        self::assertSame('2026-11-07 10:30:00', $this->clock()->localToUtc('2026-11-07 12:30', 'Europe/Vilnius')->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-07 10:30:00', $this->clock()->localToUtc('2026-11-07T12:30', 'Europe/Vilnius')->format('Y-m-d H:i:s'));
    }

    public function testNonexistentLocalTimeShiftsForward(): void
    {
        $c = $this->clock();
        // US spring forward 2026-03-08: 02:00 -> 03:00, so 02:30 does not exist
        self::assertTrue($c->isNonexistentLocalTime('2026-03-08 02:30', 'America/New_York'));
        self::assertFalse($c->isNonexistentLocalTime('2026-03-08 03:30', 'America/New_York'));
        // 02:30 -> 03:30 EDT (UTC-4) = 07:30 UTC
        self::assertSame('2026-03-08 07:30:00', $c->localToUtc('2026-03-08 02:30', 'America/New_York')->format('Y-m-d H:i:s'));
    }

    public function testAmbiguousLocalTimeUsesFirstOccurrence(): void
    {
        $c = $this->clock();
        // US fall back 2026-11-01: 01:30 happens twice (EDT then EST)
        self::assertTrue($c->isAmbiguousLocalTime('2026-11-01 01:30', 'America/New_York'));
        self::assertFalse($c->isAmbiguousLocalTime('2026-11-01 03:30', 'America/New_York'));
        self::assertFalse($c->isAmbiguousLocalTime('2026-11-01 01:30', 'UTC'));
        // first occurrence = EDT (UTC-4) = 05:30 UTC
        self::assertSame('2026-11-01 05:30:00', $c->localToUtc('2026-11-01 01:30', 'America/New_York')->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string}> */
    public static function badWallClocks(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['tomorrow-ish'];
        yield 'invalid day' => ['2026-02-30 10:00'];
        yield 'invalid hour' => ['2026-02-10 25:00'];
        yield 'date only' => ['2026-02-10'];
    }

    #[DataProvider('badWallClocks')]
    public function testLocalToUtcRejectsGarbage(string $input): void
    {
        $this->expectException(InvalidDeadline::class);
        $this->clock()->localToUtc($input, 'UTC');
    }
}
