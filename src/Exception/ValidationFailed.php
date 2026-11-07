<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ValidationFailed extends AppException
{
    /** @var array<string, list<string>> */
    private array $violations = [];

    public static function fromViolations(ConstraintViolationListInterface $list): self
    {
        $e = new self('Validation failed.');
        foreach ($list as $v) {
            $e->violations[$v->getPropertyPath() ?: '_'][] = (string) $v->getMessage();
        }

        return $e;
    }

    /** @return array<string, list<string>> */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getHttpStatus(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'validation_failed';
    }
}
