<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\User;
use App\Industry\Application\ItemVolumes;
use App\Industry\Application\JumpDistances;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\IndustryProjectService;

final readonly class CourierOverviewBuilder
{
    public const string REASON_UNRECOGNIZED_STATUS = 'unrecognized_status';

    private const int COLLATERAL_DECIMALS = 2;

    public function __construct(
        private IndustryProjectCourierContractRepository $linkRepository,
        private CourierContractReader $contractReader,
        private CourierQuoteCalculator $quoteCalculator,
        private ItemVolumes $itemVolumes,
        private JumpDistances $jumpDistances,
        private IndustryProjectService $projectService,
        private MapSolarSystemRepository $solarSystemRepository,
        private PurchaseOriginResolver $originResolver,
    ) {
    }

    public function build(IndustryProject $project, User $viewer): CourierOverview
    {
        $owner = $project->getOwner();
        $canLink = $viewer === $owner;
        $link = $this->linkRepository->findOneByProject($project);

        $workshopSolarSystemId = $this->projectService->favoriteSystemsOf($owner)->manufacturingSystemId;
        $purchases = $this->purchasesOf($project, $workshopSolarSystemId);
        $originQuotes = $this->originQuotesOf($purchases['purchasesByOrigin'], $workshopSolarSystemId);
        $mainOrigin = array_shift($originQuotes);
        $quote = null === $mainOrigin
            ? $this->quoteCalculator->quote([], [], null !== $workshopSolarSystemId, null)
            : $mainOrigin->quote;

        $read = null !== $link || $canLink
            ? $this->contractReader->read($owner, $project->getCreatedAt())
            : null;
        $linked = null !== $link && null !== $read ? $this->linkedContractOf($link, $read) : null;
        $lineState = null === $linked ? CourierContractState::NotShipped : $linked->state;

        return new CourierOverview(
            $quote,
            $mainOrigin?->solarSystemId,
            $mainOrigin?->solarSystemName,
            $workshopSolarSystemId,
            null === $workshopSolarSystemId ? null : $this->solarSystemNameOf($workshopSolarSystemId),
            $canLink,
            $linked,
            array_map(
                static fn (array $line): CourierLine => new CourierLine($line['bomLineId'], $line['typeId'], $line['purchasedQuantity'], $lineState),
                $purchases['lines'],
            ),
            $canLink && null !== $read ? $this->contractReader->candidatesIn($read, $quote) : [],
            $originQuotes,
            $this->unknownOriginLinesOf($purchases['unknownOriginQuantityByTypeId']),
        );
    }

    /**
     * @return array{
     *     purchasesByOrigin: array<int, list<array{typeId: int, quantity: int, totalPrice: float}>>,
     *     unknownOriginQuantityByTypeId: array<int, int>,
     *     lines: list<array{bomLineId: string, typeId: int, purchasedQuantity: int}>
     * }
     */
    private function purchasesOf(IndustryProject $project, ?int $workshopSolarSystemId): array
    {
        $purchasesByOrigin = [];
        $unknownOriginQuantityByTypeId = [];
        $lines = [];
        foreach ($project->getBomItems() as $bomLine) {
            $shippedQuantity = 0;
            foreach ($bomLine->getPurchases() as $purchase) {
                if ($purchase->getQuantity() <= 0) {
                    continue;
                }
                $originSolarSystemId = $this->originResolver->solarSystemIdOf($purchase);
                if (null === $originSolarSystemId) {
                    $unknownOriginQuantityByTypeId[$purchase->getTypeId()] ??= 0;
                    $unknownOriginQuantityByTypeId[$purchase->getTypeId()] += $purchase->getQuantity();
                    continue;
                }
                if ($originSolarSystemId === $workshopSolarSystemId) {
                    continue;
                }
                $purchasesByOrigin[$originSolarSystemId][] = ['typeId' => $purchase->getTypeId(), 'quantity' => $purchase->getQuantity(), 'totalPrice' => $purchase->getTotalPrice()];
                $shippedQuantity += $purchase->getQuantity();
            }
            if ($shippedQuantity > 0) {
                $lines[] = ['bomLineId' => $bomLine->getId()->toRfc4122(), 'typeId' => $bomLine->getTypeId(), 'purchasedQuantity' => $shippedQuantity];
            }
        }

        return ['purchasesByOrigin' => $purchasesByOrigin, 'unknownOriginQuantityByTypeId' => $unknownOriginQuantityByTypeId, 'lines' => $lines];
    }

    /**
     * @param array<int, list<array{typeId: int, quantity: int, totalPrice: float}>> $purchasesByOrigin
     *
     * @return list<CourierOriginQuote>
     */
    private function originQuotesOf(array $purchasesByOrigin, ?int $workshopSolarSystemId): array
    {
        $collateralByOrigin = array_map(
            static fn (array $purchases): float => round(array_sum(array_column($purchases, 'totalPrice')), self::COLLATERAL_DECIMALS),
            $purchasesByOrigin,
        );
        $originIds = array_keys($purchasesByOrigin);
        usort($originIds, static fn (int $left, int $right): int => [$collateralByOrigin[$right], $left] <=> [$collateralByOrigin[$left], $right]);

        return array_map(
            fn (int $originSolarSystemId): CourierOriginQuote => new CourierOriginQuote(
                $originSolarSystemId,
                $this->solarSystemNameOf($originSolarSystemId),
                $this->quoteCalculator->quote(
                    $purchasesByOrigin[$originSolarSystemId],
                    $this->volumeByTypeId($purchasesByOrigin[$originSolarSystemId]),
                    null !== $workshopSolarSystemId,
                    null === $workshopSolarSystemId ? null : $this->jumpDistances->jumpsBetween($originSolarSystemId, $workshopSolarSystemId),
                ),
            ),
            $originIds,
        );
    }

    /**
     * @param array<int, int> $quantityByTypeId
     *
     * @return list<CourierUnknownOriginLine>
     */
    private function unknownOriginLinesOf(array $quantityByTypeId): array
    {
        ksort($quantityByTypeId);

        return array_map(
            static fn (int $typeId, int $quantity): CourierUnknownOriginLine => new CourierUnknownOriginLine($typeId, $quantity),
            array_keys($quantityByTypeId),
            array_values($quantityByTypeId),
        );
    }

    private function solarSystemNameOf(int $solarSystemId): ?string
    {
        return $this->solarSystemRepository->findBySolarSystemId($solarSystemId)?->getSolarSystemName();
    }

    /**
     * @param list<array{typeId: int, quantity: int, totalPrice: float}> $purchases
     *
     * @return array<int, float|null>
     */
    private function volumeByTypeId(array $purchases): array
    {
        $volumes = [];
        foreach ($purchases as $purchase) {
            $volumes[$purchase['typeId']] = $this->itemVolumes->volumeOf($purchase['typeId']);
        }

        return $volumes;
    }

    private function linkedContractOf(IndustryProjectCourierContract $link, CourierContractsRead $read): CourierLinkedContract
    {
        foreach ($read->contracts as $contract) {
            if ($contract->contractId === $link->getContractId()) {
                return new CourierLinkedContract(
                    $contract->contractId,
                    $contract->characterId,
                    $contract->state,
                    CourierContractState::Unknown === $contract->state ? self::REASON_UNRECOGNIZED_STATUS : null,
                    $contract->volume,
                    $contract->reward,
                    $contract->collateral,
                    $contract->startLocationId,
                    $contract->endLocationId,
                    $contract->issuedAt,
                    $link->getLinkedAt(),
                );
            }
        }

        return new CourierLinkedContract(
            $link->getContractId(),
            $link->getCharacterId(),
            CourierContractState::Unknown,
            $read->unreadableReasonByCharacterId[$link->getCharacterId()] ?? CourierContractReader::REASON_CONTRACT_NOT_FOUND,
            null,
            null,
            null,
            null,
            null,
            null,
            $link->getLinkedAt(),
        );
    }
}
