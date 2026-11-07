<?php

declare(strict_types=1);

namespace App\Exception;

final class TaskNotFound extends AppException
{
    public function __construct(string $message = 'Task not found.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 404;
    }

    public function getErrorCode(): string
    {
        return 'task_not_found';
    }
}
