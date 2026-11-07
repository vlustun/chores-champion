<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    public function testMasksKeysAndEmails(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Info,
            'Login failed for bob@example.com',
            ['password' => 'hunter2', 'nested' => ['_token' => 'abc', 'note' => 'mail me at a.b+c@x.org'], 'user_id' => 5],
            ['email' => 'x@y.zz'],
        );

        $out = (new SensitiveDataProcessor())($record);

        self::assertSame('Login failed for ***@***', $out->message);
        self::assertSame('***', $out->context['password']);
        self::assertSame('***', $out->context['nested']['_token']);
        self::assertSame('mail me at ***@***', $out->context['nested']['note']);
        self::assertSame(5, $out->context['user_id']);
        self::assertSame('***', $out->extra['email']);
    }
}
