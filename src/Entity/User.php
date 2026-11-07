<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(name: 'phone_nr', length: 32, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9 ()\-]{6,25}$/', message: 'Enter a valid phone number.')]
    private ?string $phoneNr = null;

    #[ORM\Column]
    private string $password = '';

    /** Transient (admin forms): hashed by the controller, never persisted or serialised. */
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')]
    private ?string $plainPassword = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /** IANA identifier, e.g. Europe/Vilnius. */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    #[Assert\NotBlank]
    #[Assert\Timezone]
    private string $timezone = 'UTC';

    /** True once the user picked a timezone by hand: auto-detection then stops overwriting it. */
    #[ORM\Column(name: 'timezone_manual', options: ['default' => false])]
    private bool $timezoneManual = false;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

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

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getPhoneNr(): ?string
    {
        return $this->phoneNr;
    }

    public function setPhoneNr(?string $phoneNr): static
    {
        $phoneNr = null === $phoneNr ? null : trim($phoneNr);
        $this->phoneNr = '' === $phoneNr ? null : $phoneNr;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('User has no email identifier.');
        }

        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values($roles);

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = '' === $plainPassword ? null : $plainPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function isTimezoneManual(): bool
    {
        return $this->timezoneManual;
    }

    public function setTimezoneManual(bool $manual): static
    {
        $this->timezoneManual = $manual;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->name ?: $this->email;
    }

    /** Hash must not end up in session payload in plain form (Symfony 7.3+ crc32c trick). */
    public function __serialize(): array
    {
        $data = (array) $this;
        unset($data["\0".self::class."\0plainPassword"]);
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
