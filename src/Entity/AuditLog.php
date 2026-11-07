<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

/** Append-only. Updates and deletes via the ORM are refused. */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLog
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(name: 'entity_type', length: 100)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', length: 64, nullable: true)]
    private ?string $entityId;

    #[ORM\Column(length: 16)]
    private string $action;

    /** @var array<string, array{old: mixed, new: mixed}> */
    #[ORM\Column(type: 'json')]
    private array $changes;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'request_id', length: 36, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, array{old: mixed, new: mixed}> $changes */
    public function __construct(
        ?User $actor,
        string $entityType,
        ?string $entityId,
        string $action,
        array $changes,
        ?string $ip,
        ?string $requestId,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->actor = $actor;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->action = $action;
        $this->changes = $changes;
        $this->ip = $ip;
        $this->requestId = $requestId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, array{old: mixed, new: mixed}> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
