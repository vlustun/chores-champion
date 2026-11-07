<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidDeadline extends AppException
{
    public function __construct(string $message = 'Invalid deadline.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'invalid_deadline';
    }
}
