<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidStatusTransition extends AppException
{
    public function __construct(string $message = 'This action is not allowed in the current status.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'invalid_status_transition';
    }
}
