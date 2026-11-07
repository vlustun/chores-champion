<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaskTypeRepository::class)]
#[ORM\Table(name: 'task_type')]
#[ORM\UniqueConstraint(name: 'uniq_task_type_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_task_type_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'A task type with this name already exists.')]
#[UniqueEntity(fields: ['slug'], message: 'A task type with this slug already exists.')]
class TaskType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name = '';

    #[ORM\Column(length: 80)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Slug may contain lowercase letters, digits and dashes.')]
    private string $slug = '';

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        if ('' === $this->slug) {
            $this->slug = $this->slugify($this->name);
        }

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Empty input regenerates the slug from the name. */
    public function setSlug(?string $slug): static
    {
        $slug = trim($slug ?? '');
        $this->slug = '' === $slug ? $this->slugify($this->name) : $slug;

        return $this;
    }

    private function slugify(string $name): string
    {
        return '' === $name ? '' : mb_strtolower((new AsciiSlugger())->slug($name)->toString());
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
