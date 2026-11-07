<?php

declare(strict_types=1);

namespace App\Exception;

final class TaskAlreadyClaimed extends AppException
{
    public function __construct(string $message = 'You already have this task open.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'task_already_claimed';
    }
}
