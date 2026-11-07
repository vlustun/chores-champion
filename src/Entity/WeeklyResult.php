<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WeeklyResultRepository;
use Doctrine\ORM\Mapping as ORM;

/** Placeholder: one summary row per user per local week. Reward definitions are deferred. */
#[ORM\Entity(repositoryClass: WeeklyResultRepository::class)]
#[ORM\Table(name: 'weekly_result')]
#[ORM\UniqueConstraint(name: 'uniq_weekly_user_week', columns: ['user_id', 'week_key'])]
#[ORM\Index(name: 'idx_weekly_week', columns: ['week_key'])]
class WeeklyResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(name: 'week_key', length: 10)]
    private string $weekKey;

    #[ORM\Column(name: 'week_start_utc', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $weekStartUtc;

    #[ORM\Column(name: 'total_points', options: ['default' => 0])]
    private int $totalPoints = 0;

    #[ORM\Column(name: 'completed_count', options: ['default' => 0])]
    private int $completedCount = 0;

    #[ORM\Column(name: 'rank_in_week', nullable: true)]
    private ?int $rank = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $weekKey, \DateTimeImmutable $weekStartUtc)
    {
        $this->user = $user;
        $this->weekKey = $weekKey;
        $this->weekStartUtc = $weekStartUtc;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getWeekStartUtc(): \DateTimeImmutable
    {
        return $this->weekStartUtc;
    }

    public function getTotalPoints(): int
    {
        return $this->totalPoints;
    }

    public function setTotalPoints(int $points): static
    {
        $this->totalPoints = $points;

        return $this;
    }

    public function getCompletedCount(): int
    {
        return $this->completedCount;
    }

    public function setCompletedCount(int $count): static
    {
        $this->completedCount = $count;

        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;

        return $this;
    }
}
