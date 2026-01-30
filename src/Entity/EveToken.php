<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EveTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EveTokenRepository::class)]
#[ORM\Table(name: 'eve_tokens')]
class EveToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: Character::class, inversedBy: 'eveToken')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Character $character = null;

    #[ORM\Column(type: 'text')]
    private string $accessToken;

    #[ORM\Column(type: 'text')]
    private string $refreshTokenEncrypted;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $accessTokenExpiresAt;

    /** @var array<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRefreshTokenEncrypted(): string
    {
        return $this->refreshTokenEncrypted;
    }

    public function setRefreshTokenEncrypted(string $refreshTokenEncrypted): static
    {
        $this->refreshTokenEncrypted = $refreshTokenEncrypted;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAccessTokenExpiresAt(): \DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    public function setAccessTokenExpiresAt(\DateTimeImmutable $accessTokenExpiresAt): static
    {
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param array<string> $scopes
     */
    public function setScopes(array $scopes): static
    {
        $this->scopes = $scopes;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @param array<string> $requiredScopes
     */
    public function hasAllScopes(array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $this->scopes, true)) {
                return false;
            }
        }

        return true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isExpired(): bool
    {
        return $this->accessTokenExpiresAt <= new \DateTimeImmutable();
    }

    public function isExpiringSoon(int $seconds = 300): bool
    {
        $threshold = new \DateTimeImmutable("+{$seconds} seconds");

        return $this->accessTokenExpiresAt <= $threshold;
    }
}
