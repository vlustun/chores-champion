<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Enum\TaskPriority;
use App\Service\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerDiffTest extends TestCase
{
    public function testDiffKeepsOnlyRealChanges(): void
    {
        $diff = AuditLogger::diff([
            'title' => ['Old', 'New'],
            'description' => ['same', 'same'],
            'updatedAt' => [new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')],
        ]);

        self::assertSame(['title' => ['old' => 'Old', 'new' => 'New']], $diff);
    }

    public function testEnumsDatesAndNullsAreNormalised(): void
    {
        $diff = AuditLogger::diff([
            'priority' => [TaskPriority::Low, TaskPriority::Urgent],
            'deadline' => [null, new \DateTimeImmutable('2026-03-08 12:00:00', new \DateTimeZone('America/New_York'))],
        ]);

        self::assertSame(['old' => 'low', 'new' => 'urgent'], $diff['priority']);
        self::assertSame(['old' => null, 'new' => '2026-03-08T16:00:00.000000Z'], $diff['deadline']); // UTC
    }

    public function testPasswordsAreMasked(): void
    {
        $diff = AuditLogger::diff(['password' => ['$2y$old-hash', '$2y$new-hash']]);

        self::assertSame(['old' => '***', 'new' => '***'], $diff['password']);
        self::assertStringNotContainsString('hash', json_encode($diff, \JSON_THROW_ON_ERROR));
    }

    public function testDateOnlyDifferenceInTimezoneIsNotAChange(): void
    {
        $a = new \DateTimeImmutable('2026-03-08 12:00:00 UTC');
        $b = new \DateTimeImmutable('2026-03-08 08:00:00 America/New_York');

        self::assertSame([], AuditLogger::diff(['x' => [$a, $b]]));
    }

    public function testSnapshotForCreateAndDelete(): void
    {
        self::assertSame(['title' => ['old' => null, 'new' => 'A']], AuditLogger::snapshot(['title' => 'A', 'updatedAt' => new \DateTimeImmutable()], false));
        self::assertSame(['title' => ['old' => 'A', 'new' => null]], AuditLogger::snapshot(['title' => 'A'], true));
    }
}
