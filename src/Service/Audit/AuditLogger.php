<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditLog;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Builds AuditLog rows (diffs, actor, request context) and mirrors them to the "audit" log channel. */
class AuditLogger
{
    /** Never part of a diff. */
    public const IGNORED_FIELDS = ['updatedAt', 'createdAt'];
    /** Recorded as changed, but the value is masked. */
    public const MASKED_FIELDS = ['password'];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet Doctrine style field => [old, new]
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $changeSet): array
    {
        $diff = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if (\in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }
            $old = self::normalize($old);
            $new = self::normalize($new);
            if ($old === $new) {
                continue;
            }
            if (\in_array($field, self::MASKED_FIELDS, true)) {
                $old = null === $old ? null : '***';
                $new = null === $new ? null : '***';
            }
            $diff[$field] = ['old' => $old, 'new' => $new];
        }

        return $diff;
    }

    /** Snapshot for create/delete: field => value, as a diff against null. */
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function snapshot(array $data, bool $deleted): array
    {
        $changeSet = [];
        foreach ($data as $field => $value) {
            $changeSet[$field] = $deleted ? [$value, null] : [null, $value];
        }

        return self::diff($changeSet);
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            $value instanceof \BackedEnum => $value->value,
            \is_object($value) && method_exists($value, 'getId') => (new \ReflectionClass($value))->getShortName().'#'.$value->getId(),
            \is_object($value) => $value::class,
            \is_array($value) => array_map(static fn ($v) => self::normalize($v), $value),
            default => $value,
        };
    }

    public function currentActor(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    public function currentActorId(): ?int
    {
        return $this->currentActor()?->getId();
    }

    public function currentRequestId(): ?string
    {
        $id = $this->requests->getMainRequest()?->attributes->get('request_id');

        return \is_string($id) ? $id : null;
    }

    /** @param array<string, array{old: mixed, new: mixed}> $changes */
    public function build(string $entityType, ?string $entityId, string $action, array $changes, ?User $actor): AuditLog
    {
        $request = $this->requests->getMainRequest();

        return new AuditLog(
            $actor,
            $entityType,
            $entityId,
            $action,
            $changes,
            $request?->getClientIp(),
            $this->currentRequestId(),
        );
    }

    public const PASSWORD_RESET_REQUESTED = 'pw_reset_request';
    public const PASSWORD_RESET = 'pw_reset';
    public const PASSWORD_CHANGED = 'pw_change';
    public const PASSWORD_RESET_SENT = 'pw_reset_sent';

    /**
     * Event row for a password lifecycle step on $target. Records only that it happened, never a hash or token.
     * The caller persists and flushes it. $actor defaults to the logged-in user, then to the target itself.
     */
    public function passwordEvent(User $target, string $event, ?User $actor = null): AuditLog
    {
        $actor ??= $this->currentActor() ?? $target;

        return $this->build('User', null !== $target->getId() ? (string) $target->getId() : null, $event, ['event' => ['old' => null, 'new' => $event]], $actor);
    }

    public function mirror(AuditLog $log): void
    {
        $this->logger->info('audit', [
            'entity_type' => $log->getEntityType(),
            'entity_id' => $log->getEntityId(),
            'action' => $log->getAction(),
            'actor_id' => $log->getActor()?->getId(),
            'fields' => array_keys($log->getChanges()),
        ]);
    }
}
