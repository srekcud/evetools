<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserLedgerSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserLedgerSettingsRepository::class)]
#[ORM\Table(name: 'user_ledger_settings')]
class UserLedgerSettings
{
    public const CORP_PROJECT_ACCOUNTING_PVE = 'pve';
    public const CORP_PROJECT_ACCOUNTING_MINING = 'mining';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMiningSyncAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $autoSyncEnabled = true;

    /**
     * How to account for corp project contributions:
     * - 'pve': Count ISK received in PVE module, exclude from mining stats
     * - 'mining': Count ore value in mining stats, exclude ISK from PVE stats
     */
    #[ORM\Column(type: 'string', length: 10)]
    private string $corpProjectAccounting = self::CORP_PROJECT_ACCOUNTING_PVE;

    /** @var int[] Type IDs to exclude from mining stats (e.g., ice, gas) */
    #[ORM\Column(type: 'json')]
    private array $excludedTypeIds = [];

    /** @var int[] Type IDs considered sold by default */
    #[ORM\Column(type: 'json')]
    private array $defaultSoldTypeIds = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getLastMiningSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastMiningSyncAt;
    }

    public function setLastMiningSyncAt(?\DateTimeImmutable $lastMiningSyncAt): static
    {
        $this->lastMiningSyncAt = $lastMiningSyncAt;
        return $this;
    }

    public function isAutoSyncEnabled(): bool
    {
        return $this->autoSyncEnabled;
    }

    public function setAutoSyncEnabled(bool $autoSyncEnabled): static
    {
        $this->autoSyncEnabled = $autoSyncEnabled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCorpProjectAccounting(): string
    {
        return $this->corpProjectAccounting;
    }

    public function setCorpProjectAccounting(string $corpProjectAccounting): static
    {
        if (!in_array($corpProjectAccounting, [self::CORP_PROJECT_ACCOUNTING_PVE, self::CORP_PROJECT_ACCOUNTING_MINING], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid corpProjectAccounting value: %s. Must be "%s" or "%s".',
                $corpProjectAccounting,
                self::CORP_PROJECT_ACCOUNTING_PVE,
                self::CORP_PROJECT_ACCOUNTING_MINING
            ));
        }

        $this->corpProjectAccounting = $corpProjectAccounting;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * @return int[]
     */
    public function getExcludedTypeIds(): array
    {
        return $this->excludedTypeIds;
    }

    /**
     * @param int[] $excludedTypeIds
     */
    public function setExcludedTypeIds(array $excludedTypeIds): static
    {
        $this->excludedTypeIds = $excludedTypeIds;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addExcludedTypeId(int $typeId): static
    {
        if (!in_array($typeId, $this->excludedTypeIds, true)) {
            $this->excludedTypeIds[] = $typeId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function removeExcludedTypeId(int $typeId): static
    {
        $this->excludedTypeIds = array_values(array_filter(
            $this->excludedTypeIds,
            fn($id) => $id !== $typeId
        ));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * @return int[]
     */
    public function getDefaultSoldTypeIds(): array
    {
        return $this->defaultSoldTypeIds;
    }

    /**
     * @param int[] $defaultSoldTypeIds
     */
    public function setDefaultSoldTypeIds(array $defaultSoldTypeIds): static
    {
        $this->defaultSoldTypeIds = $defaultSoldTypeIds;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addDefaultSoldTypeId(int $typeId): static
    {
        if (!in_array($typeId, $this->defaultSoldTypeIds, true)) {
            $this->defaultSoldTypeIds[] = $typeId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function removeDefaultSoldTypeId(int $typeId): static
    {
        $this->defaultSoldTypeIds = array_values(array_filter(
            $this->defaultSoldTypeIds,
            fn($id) => $id !== $typeId
        ));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Check if corp project contributions should be counted in PVE stats.
     */
    public function shouldCountCorpProjectInPve(): bool
    {
        return $this->corpProjectAccounting === self::CORP_PROJECT_ACCOUNTING_PVE;
    }

    /**
     * Check if corp project contributions should be counted in mining stats.
     */
    public function shouldCountCorpProjectInMining(): bool
    {
        return $this->corpProjectAccounting === self::CORP_PROJECT_ACCOUNTING_MINING;
    }
}
