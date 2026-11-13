<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SdeBuildStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SdeBuildStateRepository::class)]
#[ORM\Table(name: 'sde_build_state')]
class SdeBuildState
{
    public const int SINGLE_ROW_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::SINGLE_ROW_ID;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $importedBuildNumber = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $importedReleaseDate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $importedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $latestBuildNumber = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $latestReleaseDate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $latestCheckedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $notifiedBuildNumber = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getImportedBuildNumber(): ?int
    {
        return $this->importedBuildNumber;
    }

    public function setImportedBuildNumber(?int $importedBuildNumber): static
    {
        $this->importedBuildNumber = $importedBuildNumber;

        return $this;
    }

    public function getImportedReleaseDate(): ?\DateTimeImmutable
    {
        return $this->importedReleaseDate;
    }

    public function setImportedReleaseDate(?\DateTimeImmutable $importedReleaseDate): static
    {
        $this->importedReleaseDate = $importedReleaseDate;

        return $this;
    }

    public function getImportedAt(): ?\DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(?\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getLatestBuildNumber(): ?int
    {
        return $this->latestBuildNumber;
    }

    public function setLatestBuildNumber(?int $latestBuildNumber): static
    {
        $this->latestBuildNumber = $latestBuildNumber;

        return $this;
    }

    public function getLatestReleaseDate(): ?\DateTimeImmutable
    {
        return $this->latestReleaseDate;
    }

    public function setLatestReleaseDate(?\DateTimeImmutable $latestReleaseDate): static
    {
        $this->latestReleaseDate = $latestReleaseDate;

        return $this;
    }

    public function getLatestCheckedAt(): ?\DateTimeImmutable
    {
        return $this->latestCheckedAt;
    }

    public function setLatestCheckedAt(?\DateTimeImmutable $latestCheckedAt): static
    {
        $this->latestCheckedAt = $latestCheckedAt;

        return $this;
    }

    public function getNotifiedBuildNumber(): ?int
    {
        return $this->notifiedBuildNumber;
    }

    public function setNotifiedBuildNumber(?int $notifiedBuildNumber): static
    {
        $this->notifiedBuildNumber = $notifiedBuildNumber;

        return $this;
    }
}
