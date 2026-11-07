<?php

declare(strict_types=1);

namespace App\Exception;

final class TaskNotAvailable extends AppException
{
    public function __construct(string $message = 'This task is not available.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'task_not_available';
    }
}
