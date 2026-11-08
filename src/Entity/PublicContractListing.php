<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublicContractListingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PublicContractListingRepository::class)]
#[ORM\Table(name: 'public_contract_listings')]
#[ORM\UniqueConstraint(columns: ['contract_id'])]
#[ORM\Index(columns: ['region_id', 'type_id'])]
#[ORM\Index(columns: ['region_id', 'missed_polls'])]
#[ORM\Index(columns: ['last_seen_at'])]
class PublicContractListing
{
    public const int CONFIRMATION_POLLS = 2;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $contractId;

    #[ORM\Column(type: 'integer')]
    private int $regionId;

    #[ORM\Column(type: 'bigint')]
    private int $issuerId;

    #[ORM\Column(type: 'float')]
    private float $price;

    /** @var list<array{typeId: int, quantity: int}> */
    #[ORM\Column(type: 'json')]
    private array $items;

    #[ORM\Column(type: 'string', length: 1000)]
    private string $itemsSignature;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $typeId;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantity;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateExpired;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $missedPolls = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disappearedAt = null;

    private function __construct()
    {
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $items
     */
    public static function firstSeen(
        int $contractId,
        int $regionId,
        int $issuerId,
        float $price,
        array $items,
        \DateTimeImmutable $dateExpired,
        \DateTimeImmutable $now,
    ): self {
        $listing = new self();
        $listing->contractId = $contractId;
        $listing->regionId = $regionId;
        $listing->issuerId = $issuerId;
        $listing->price = $price;
        $listing->items = self::aggregatedByType($items);
        $listing->itemsSignature = implode(';', array_map(
            static fn (array $item): string => $item['typeId'] . ':' . $item['quantity'],
            $listing->items,
        ));
        $isSingleType = 1 === count($listing->items);
        $listing->typeId = $isSingleType ? $listing->items[0]['typeId'] : null;
        $listing->quantity = $isSingleType ? $listing->items[0]['quantity'] : null;
        $listing->dateExpired = $dateExpired;
        $listing->firstSeenAt = $now;
        $listing->lastSeenAt = $now;

        return $listing;
    }

    public function seenAgain(\DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
        $this->missedPolls = 0;
        $this->disappearedAt = null;
    }

    public function missed(\DateTimeImmutable $now): void
    {
        ++$this->missedPolls;
        $this->disappearedAt ??= $now;
    }

    public function isDisappearanceConfirmed(): bool
    {
        return $this->missedPolls >= self::CONFIRMATION_POLLS;
    }

    /**
     * @param list<array{typeId: int, quantity: int}> $items
     * @return list<array{typeId: int, quantity: int}>
     */
    private static function aggregatedByType(array $items): array
    {
        $quantityByType = [];
        foreach ($items as $item) {
            $quantityByType[$item['typeId']] = ($quantityByType[$item['typeId']] ?? 0) + $item['quantity'];
        }
        ksort($quantityByType);

        return array_map(
            static fn (int $typeId, int $quantity): array => ['typeId' => $typeId, 'quantity' => $quantity],
            array_keys($quantityByType),
            $quantityByType,
        );
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getContractId(): int
    {
        return $this->contractId;
    }

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function getIssuerId(): int
    {
        return $this->issuerId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    /**
     * @return list<array{typeId: int, quantity: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getItemsSignature(): string
    {
        return $this->itemsSignature;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getDateExpired(): \DateTimeImmutable
    {
        return $this->dateExpired;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getMissedPolls(): int
    {
        return $this->missedPolls;
    }

    public function getDisappearedAt(): ?\DateTimeImmutable
    {
        return $this->disappearedAt;
    }
}
