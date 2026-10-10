<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\TrustedDeviceInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
// Unicité insensible à la casse : « Bob » et « bob » ne peuvent pas coexister.
#[UniqueEntity(fields: ['username'], message: 'Ce pseudo est déjà pris.', repositoryMethod: 'findByUsernameInsensitive')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EmailTwoFactorInterface, TrustedDeviceInterface
{
    /** Durée de validité d'un code MFA envoyé par e-mail. */
    public const MFA_CODE_TTL = '+10 minutes';

    public const USERNAME_MAX_LENGTH = 30;
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /** Pseudo public et unique (claims « preferred_username » et « name »). La casse saisie est conservée. */
    #[ORM\Column(length: self::USERNAME_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Veuillez choisir un pseudo.')]
    #[Assert\Length(min: 3, max: self::USERNAME_MAX_LENGTH, minMessage: 'Le pseudo doit contenir au moins {{ limit }} caractères.', maxMessage: 'Le pseudo ne peut pas dépasser {{ limit }} caractères.')]
    #[Assert\Regex(pattern: self::USERNAME_PATTERN, message: 'Le pseudo ne peut contenir que des lettres, des chiffres et les caractères . _ -')]
    private string $username = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * Applications (clients OAuth2) auxquelles l'utilisateur a accès.
     *
     * @var Collection<int, Application>
     */
    #[ORM\ManyToMany(targetEntity: Application::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_application')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'application_id', referencedColumnName: 'identifier', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $applications;

    /** Code MFA (6 chiffres) envoyé par e-mail, et sa date d'expiration. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $emailAuthCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailAuthCodeExpiresAt = null;

    /**
     * Incrémenté pour invalider tous les « appareils de confiance » (cookies MFA) de l'utilisateur.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $trustedTokenVersion = 0;

    /**
     * Incrémenté pour déconnecter l'utilisateur de toutes ses sessions (web et « rester connecté »).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $sessionVersion = 0;

    /** Mot de passe en clair, uniquement utilisé par les formulaires (jamais persisté). */
    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = trim($username);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_diff($roles, ['ROLE_USER'])));

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
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    /**
     * @return Collection<int, Application>
     */
    public function getApplications(): Collection
    {
        return $this->applications;
    }

    public function addApplication(Application $application): static
    {
        if (!$this->applications->contains($application)) {
            $this->applications->add($application);
        }

        return $this;
    }

    public function removeApplication(Application $application): static
    {
        $this->applications->removeElement($application);

        return $this;
    }

    public function hasAccessTo(Application $application): bool
    {
        foreach ($this->applications as $granted) {
            if ($granted->getIdentifier() === $application->getIdentifier()) {
                return true;
            }
        }

        return false;
    }

    // --- MFA par e-mail (scheb/2fa-email) ---

    public function isEmailAuthEnabled(): bool
    {
        return true;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    /**
     * Retourne null si aucun code n'est en cours ou s'il a expiré : un code expiré est donc refusé.
     */
    public function getEmailAuthCode(): ?string
    {
        if (null === $this->emailAuthCode || null === $this->emailAuthCodeExpiresAt || $this->emailAuthCodeExpiresAt < new \DateTimeImmutable()) {
            return null;
        }

        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->emailAuthCode = $authCode;
        $this->emailAuthCodeExpiresAt = new \DateTimeImmutable(self::MFA_CODE_TTL);
    }

    public function getEmailAuthCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailAuthCodeExpiresAt;
    }

    public function invalidateEmailAuthCode(): void
    {
        $this->emailAuthCode = null;
        $this->emailAuthCodeExpiresAt = null;
    }

    public function getTrustedTokenVersion(): int
    {
        return $this->trustedTokenVersion;
    }

    public function getSessionVersion(): int
    {
        return $this->sessionVersion;
    }

    /**
     * Invalide toutes les sessions web, les cookies « rester connecté » et les appareils de confiance MFA.
     * (Les jetons OAuth2 sont révoqués séparément par AccessRevoker.)
     */
    public function invalidateAllSessions(): void
    {
        ++$this->sessionVersion;
        ++$this->trustedTokenVersion;
        $this->invalidateEmailAuthCode();
    }

    /**
     * Nouvelle vérification MFA exigée sur tous les appareils (ex. après changement de mot de passe).
     */
    public function forgetTrustedDevices(): void
    {
        ++$this->trustedTokenVersion;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function __toString(): string
    {
        return \sprintf('%s <%s>', $this->username, $this->email);
    }

    /**
     * Ne sérialise en session qu'un hash du mot de passe (recommandation Symfony 7.3+).
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);
        unset($data["\0".self::class."\0plainPassword"], $data["\0".self::class."\0emailAuthCode"]);

        return $data;
    }
}
