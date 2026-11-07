<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskType;
use App\Entity\User;
use App\Service\Audit\AuditLogger;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * Records every create/update/delete of Task, TaskType and User with a
 * before/after diff, the acting user (null for CLI) and the request id.
 *
 * onFlush collects the diffs (ids of new rows are unknown yet),
 * postFlush writes the AuditLog rows inside the same outer transaction.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DoctrineAuditSubscriber
{
    public const AUDITED = [Task::class, TaskType::class, User::class];

    /** @var list<array{entity: object, type: string, action: string, changes: array<string, array{old: mixed, new: mixed}>, id: ?string}> */
    private array $pending = [];
    private bool $writing = false;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->writing) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isAudited($entity)) {
                $this->pending[] = $this->entry($entity, AuditLog::CREATE, AuditLogger::diff($uow->getEntityChangeSet($entity)), null);
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isAudited($entity)) {
                $changes = AuditLogger::diff($uow->getEntityChangeSet($entity));
                if ([] !== $changes) {
                    $this->pending[] = $this->entry($entity, AuditLog::UPDATE, $changes, $this->idOf($entity));
                }
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isAudited($entity)) {
                $this->pending[] = $this->entry($entity, AuditLog::DELETE, $this->deletionSnapshot($uow, $entity), $this->idOf($entity));
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->writing || [] === $this->pending) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $this->writing = true;
        try {
            $em = $args->getObjectManager();
            // The security token's User may belong to another/closed EntityManager: always use a reference.
            $actorId = $this->audit->currentActorId();
            $actor = null !== $actorId ? $em->getReference(User::class, $actorId) : null;
            foreach ($pending as $p) {
                $log = $this->audit->build($p['type'], $p['id'] ?? $this->idOf($p['entity']), $p['action'], $p['changes'], $actor);
                $em->persist($log);
                $this->audit->mirror($log);
            }
            $em->flush();
        } catch (\Throwable $e) {
            // An unrecorded change must be loud: log and rethrow so the surrounding transaction rolls back.
            $this->logger->critical('Failed to write audit log', ['exception' => $e]);
            throw $e;
        } finally {
            $this->writing = false;
        }
    }

    private function isAudited(object $entity): bool
    {
        foreach (self::AUDITED as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     *
     * @return array{entity: object, type: string, action: string, changes: array<string, array{old: mixed, new: mixed}>, id: ?string}
     */
    private function entry(object $entity, string $action, array $changes, ?string $id): array
    {
        return ['entity' => $entity, 'type' => (new \ReflectionClass($entity))->getShortName(), 'action' => $action, 'changes' => $changes, 'id' => $id];
    }

    private function idOf(object $entity): ?string
    {
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;

        return null === $id ? null : (string) $id;
    }

    /** @return array<string, array{old: mixed, new: mixed}> */
    private function deletionSnapshot(UnitOfWork $uow, object $entity): array
    {
        return AuditLogger::snapshot($uow->getOriginalEntityData($entity), true);
    }
}
