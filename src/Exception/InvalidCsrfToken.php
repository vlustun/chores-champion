<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidCsrfToken extends AppException
{
    public function __construct(string $message = 'Your session expired or the form was tampered with. Please try again.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }

    public function getHttpStatus(): int
    {
        return 403;
    }

    public function getErrorCode(): string
    {
        return 'invalid_csrf_token';
    }
}
