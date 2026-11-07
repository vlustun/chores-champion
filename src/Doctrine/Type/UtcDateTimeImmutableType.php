<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Persists every instant as UTC, regardless of the PHP default timezone
 * or the timezone of the DateTimeImmutable passed in. Microsecond precision.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'datetime_immutable';
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof \DateTimeInterface) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', \DateTimeInterface::class]);
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'string']);
        }
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if (false === $date) {
            throw ConversionException::conversionFailedFormat($value, self::NAME, self::FORMAT);
        }

        return $date;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
