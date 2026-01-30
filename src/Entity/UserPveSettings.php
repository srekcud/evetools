<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPveSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserPveSettingsRepository::class)]
#[ORM\Table(name: 'user_pve_settings')]
class UserPveSettings
{
    // Fuel type IDs - now user-configured via ammoTypeIds
    public const FUEL_TYPE_IDS = [];

    // Known beacon type IDs
    public const BEACON_TYPE_IDS = [
        60244, // CONCORD Rogue Analysis Beacon
    ];

    // PVE Loot (OPEs, Rogue Drone Data, etc.) - for contract tracking
    public const PVE_LOOT_TYPE_IDS = [
        // Overseer's Personal Effects (1st to 23rd tier)
        19400,  // 1st Tier Overseer's Personal Effects
        19401,  // 2nd Tier Overseer's Personal Effects
        19402,  // 3rd Tier Overseer's Personal Effects
        19403,  // 4th Tier Overseer's Personal Effects
        19404,  // 5th Tier Overseer's Personal Effects
        19405,  // 6th Tier Overseer's Personal Effects
        19406,  // 7th Tier Overseer's Personal Effects
        19407,  // 8th Tier Overseer's Personal Effects
        19408,  // 9th Tier Overseer's Personal Effects
        19409,  // 11th Tier Overseer's Personal Effects
        19410,  // 12th Tier Overseer's Personal Effects
        19411,  // 13th Tier Overseer's Personal Effects
        19412,  // 14th Tier Overseer's Personal Effects
        19413,  // 15th Tier Overseer's Personal Effects
        19414,  // 16th Tier Overseer's Personal Effects
        19415,  // 17th Tier Overseer's Personal Effects
        19416,  // 18th Tier Overseer's Personal Effects
        19417,  // 19th Tier Overseer's Personal Effects
        19418,  // 20th Tier Overseer's Personal Effects
        19419,  // 21st Tier Overseer's Personal Effects
        19420,  // 22nd Tier Overseer's Personal Effects
        19421,  // 23rd Tier Overseer's Personal Effects
        19422,  // 10th Tier Overseer's Personal Effects
        // Rogue Drone loot
        60459,  // Rogue Drone Infestation Data
    ];

    // Default suggested price per PVE loot item when contracted at 0 ISK
    public const PVE_LOOT_DEFAULT_PRICE_PER_ITEM = 100_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $ammoTypeIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $lootTypeIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $declinedContractIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $declinedTransactionIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $declinedLootSaleTransactionIds = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $autoSyncEnabled = true;

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

    /**
     * @return int[]
     */
    public function getAmmoTypeIds(): array
    {
        return $this->ammoTypeIds;
    }

    /**
     * @param int[] $ammoTypeIds
     */
    public function setAmmoTypeIds(array $ammoTypeIds): static
    {
        $this->ammoTypeIds = $ammoTypeIds;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addAmmoTypeId(int $typeId): static
    {
        if (!in_array($typeId, $this->ammoTypeIds, true)) {
            $this->ammoTypeIds[] = $typeId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function removeAmmoTypeId(int $typeId): static
    {
        $this->ammoTypeIds = array_values(array_filter(
            $this->ammoTypeIds,
            fn($id) => $id !== $typeId
        ));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * @return int[]
     */
    public function getLootTypeIds(): array
    {
        return $this->lootTypeIds;
    }

    /**
     * @param int[] $lootTypeIds
     */
    public function setLootTypeIds(array $lootTypeIds): static
    {
        $this->lootTypeIds = $lootTypeIds;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addLootTypeId(int $typeId): static
    {
        if (!in_array($typeId, $this->lootTypeIds, true)) {
            $this->lootTypeIds[] = $typeId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function removeLootTypeId(int $typeId): static
    {
        $this->lootTypeIds = array_values(array_filter(
            $this->lootTypeIds,
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
     * @return int[]
     */
    public function getDeclinedContractIds(): array
    {
        return $this->declinedContractIds;
    }

    public function addDeclinedContractId(int $contractId): static
    {
        if (!in_array($contractId, $this->declinedContractIds, true)) {
            $this->declinedContractIds[] = $contractId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    /**
     * @return int[]
     */
    public function getDeclinedTransactionIds(): array
    {
        return $this->declinedTransactionIds;
    }

    public function addDeclinedTransactionId(int $transactionId): static
    {
        if (!in_array($transactionId, $this->declinedTransactionIds, true)) {
            $this->declinedTransactionIds[] = $transactionId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    /**
     * @return int[]
     */
    public function getDeclinedLootSaleTransactionIds(): array
    {
        return $this->declinedLootSaleTransactionIds;
    }

    public function addDeclinedLootSaleTransactionId(int $transactionId): static
    {
        if (!in_array($transactionId, $this->declinedLootSaleTransactionIds, true)) {
            $this->declinedLootSaleTransactionIds[] = $transactionId;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    /**
     * Clear declined lists, except for IDs that should be kept.
     *
     * @param int[] $keepContractIds Contract IDs to keep in declined list
     * @param int[] $keepTransactionIds Transaction IDs to keep in declined lists
     */
    public function clearDeclinedExcept(array $keepContractIds = [], array $keepTransactionIds = []): static
    {
        // Keep only the IDs that are in the keep lists
        $this->declinedContractIds = array_values(array_intersect($this->declinedContractIds, $keepContractIds));
        $this->declinedTransactionIds = array_values(array_intersect($this->declinedTransactionIds, $keepTransactionIds));
        $this->declinedLootSaleTransactionIds = array_values(array_intersect($this->declinedLootSaleTransactionIds, $keepTransactionIds));
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): static
    {
        $this->lastSyncAt = $lastSyncAt;
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
}
