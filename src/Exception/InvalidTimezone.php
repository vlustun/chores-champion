<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidTimezone extends AppException
{
    public function __construct(string $message = 'Unknown timezone identifier.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 400;
    }

    public function getErrorCode(): string
    {
        return 'invalid_timezone';
    }
}
