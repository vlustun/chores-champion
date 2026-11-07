<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Base class for all expected, domain-level failures.
 * The message is safe to show to end users; internals go in $context (logged only).
 */
abstract class AppException extends \RuntimeException
{
    /** @param array<string, scalar|null> $context */
    public function __construct(string $message, private readonly array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    abstract public function getHttpStatus(): int;

    /** Stable machine readable code (used as problem+json "type" suffix). */
    abstract public function getErrorCode(): string;

    /** @return array<string, scalar|null> */
    public function getContext(): array
    {
        return $this->context;
    }
}
