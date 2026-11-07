<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Defence in depth against PII/secrets in logs: masks sensitive keys and e-mail addresses
 * anywhere in message, context and extra.
 */
#[AsMonologProcessor(priority: -100)]
final class SensitiveDataProcessor
{
    private const SENSITIVE_KEYS = ['password', '_password', 'plainpassword', 'token', '_token', '_csrf_token', 'authorization', 'cookie', 'secret', 'api_key', 'apikey', 'email', 'phone', 'phone_nr', 'phonenr', '_username'];
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array(mb_strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            } elseif (\is_array($value)) {
                $data[$key] = $depth < 6 ? $this->scrub($value, $depth + 1) : '[depth limit]';
            } elseif (\is_string($value)) {
                $data[$key] = $this->scrubString($value);
            }
        }

        return $data;
    }

    private function scrubString(string $value): string
    {
        return preg_replace(self::EMAIL, '***@***', $value) ?? $value;
    }
}
