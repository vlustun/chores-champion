<?php

declare(strict_types=1);

namespace App\Service\Time;

use App\Entity\User;
use App\Exception\InvalidTimezone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates IANA identifiers and resolves the effective timezone.
 * Fallback chain: user setting -> tz cookie -> app default -> UTC.
 */
final class TimezoneResolver
{
    /** @var array<string, string>|null lowercase => canonical */
    private static ?array $identifiers = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_DEFAULT_TIMEZONE)%')]
        private readonly string $defaultTimezone = 'UTC',
    ) {
    }

    /** Canonical identifier or null. Offsets ("+02:00") and abbreviations ("EST") are rejected on purpose: no DST rules. */
    public function canonical(?string $tz): ?string
    {
        if (null === $tz) {
            return null;
        }
        $tz = trim($tz);
        if ('' === $tz || \strlen($tz) > 64) {
            return null;
        }
        self::$identifiers ??= array_change_key_case(self::buildIdentifierMap(), \CASE_LOWER);

        return self::$identifiers[mb_strtolower($tz)] ?? null;
    }

    /**
     * listIdentifiers() omits Etc/GMT+N, the only zones for UTC-12..UTC-1, so add the fixed
     * ones (still IANA ids, no DST). Note POSIX sign: Etc/GMT+12 is UTC-12.
     *
     * @return array<string, string>
     */
    /** @return list<string> every accepted identifier (for pickers) */
    public static function all(): array
    {
        return array_values(self::buildIdentifierMap());
    }

    /** @return array<string, string> */
    private static function buildIdentifierMap(): array
    {
        $ids = \DateTimeZone::listIdentifiers();
        for ($i = 1; $i <= 14; ++$i) {
            if ($i <= 12) {
                $ids[] = 'Etc/GMT+'.$i;
            }
            $ids[] = 'Etc/GMT-'.$i;
        }

        return array_combine($ids, $ids);
    }

    public function isValid(?string $tz): bool
    {
        return null !== $this->canonical($tz);
    }

    public function normalize(string $tz): string
    {
        return $this->canonical($tz) ?? throw new InvalidTimezone('Unknown timezone identifier.', ['timezone' => mb_substr($tz, 0, 64)]);
    }

    /** First valid candidate, then the app default, then UTC. */
    public function resolve(?string ...$candidates): string
    {
        foreach ([...$candidates, $this->defaultTimezone] as $candidate) {
            $canonical = $this->canonical($candidate);
            if (null !== $canonical) {
                return $canonical;
            }
        }

        return 'UTC';
    }

    /**
     * Apply a browser-detected timezone. No-op when the user chose manually
     * or nothing changed. Returns true when the user was updated.
     */
    public function applyDetected(User $user, string $detected): bool
    {
        $tz = $this->normalize($detected);
        if ($user->isTimezoneManual() || $user->getTimezone() === $tz) {
            return false;
        }
        $this->logger->info('User timezone auto-detected', ['user_id' => $user->getId(), 'from' => $user->getTimezone(), 'to' => $tz]);
        $user->setTimezone($tz);
        $this->em->flush();

        return true;
    }

    /** Explicit user choice; stops further auto-detection. */
    public function applyManual(User $user, string $tz): void
    {
        $user->setTimezone($this->normalize($tz))->setTimezoneManual(true);
        $this->em->flush();
    }
}
