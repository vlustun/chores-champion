<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Exception\InvalidTimezone;
use App\Service\Time\TimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TimezoneResolverTest extends TestCase
{
    private function resolver(?EntityManagerInterface $em = null, string $default = 'Europe/London'): TimezoneResolver
    {
        return new TimezoneResolver($em ?? $this->createStub(EntityManagerInterface::class), new NullLogger(), $default);
    }

    public function testCanonicalizesCase(): void
    {
        self::assertSame('Europe/Vilnius', $this->resolver()->canonical('europe/vilnius'));
        self::assertSame('Europe/Vilnius', $this->resolver()->canonical('  Europe/Vilnius '));
    }

    /** @return iterable<string, array{?string}> */
    public static function invalid(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'offset' => ['+02:00'];
        yield 'abbreviation' => ['EST'];
        yield 'unknown' => ['Mars/Olympus']; // not a real zone
        yield 'sql injection' => ["Europe/Vilnius'; DROP TABLE app_user;--"];
        yield 'too long' => [str_repeat('a', 200)];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(?string $tz): void
    {
        self::assertFalse($this->resolver()->isValid($tz));
    }

    public function testNormalizeThrowsDomainException(): void
    {
        $this->expectException(InvalidTimezone::class);
        $this->resolver()->normalize('Nope/Nope');
    }

    public function testFallbackChain(): void
    {
        $r = $this->resolver();
        self::assertSame('Asia/Tokyo', $r->resolve('Asia/Tokyo', 'Europe/Paris'));
        self::assertSame('Europe/Paris', $r->resolve('garbage', 'Europe/Paris'));
        self::assertSame('Europe/Paris', $r->resolve(null, 'Europe/Paris'));
        self::assertSame('Europe/London', $r->resolve(null, null));
        self::assertSame('UTC', $this->resolver(null, 'also-garbage')->resolve());
    }

    public function testApplyDetectedUpdatesAndFlushes(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $user = (new User())->setTimezone('UTC');

        self::assertTrue($this->resolver($em)->applyDetected($user, 'Europe/Vilnius'));
        self::assertSame('Europe/Vilnius', $user->getTimezone());
        self::assertFalse($user->isTimezoneManual());
    }

    public function testApplyDetectedSkipsManualAndUnchanged(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $r = $this->resolver($em);

        $manual = (new User())->setTimezone('Asia/Tokyo')->setTimezoneManual(true);
        self::assertFalse($r->applyDetected($manual, 'Europe/Vilnius'));
        self::assertSame('Asia/Tokyo', $manual->getTimezone());

        $same = (new User())->setTimezone('Europe/Vilnius');
        self::assertFalse($r->applyDetected($same, 'Europe/Vilnius'));
    }

    public function testApplyDetectedRejectsInvalid(): void
    {
        $this->expectException(InvalidTimezone::class);
        $this->resolver()->applyDetected(new User(), 'bogus');
    }

    public function testApplyManualPinsTimezone(): void
    {
        $user = new User();
        $this->resolver()->applyManual($user, 'america/new_york');
        self::assertSame('America/New_York', $user->getTimezone());
        self::assertTrue($user->isTimezoneManual());
    }
}
