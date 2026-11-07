<?php

declare(strict_types=1);

namespace App\Exception;

final class ForbiddenAction extends AppException
{
    public function __construct(string $message = 'You are not allowed to do that.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 403;
    }

    public function getErrorCode(): string
    {
        return 'forbidden_action';
    }
}
