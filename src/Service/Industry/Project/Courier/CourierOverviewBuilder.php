<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Constant\EveConstants;
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

        $favoriteSystems = $this->projectService->favoriteSystemsOf($owner);
        $courierHub = $favoriteSystems->courierHub ?? throw new \LogicException('The favorite systems of a project owner always carry a courier hub.');
        $workshopSolarSystemId = $favoriteSystems->manufacturingSystemId;
        $purchases = $this->purchasesOf($project, $workshopSolarSystemId);
        $hasJitaPurchases = [] !== $purchases['jitaPurchases'];
        $quote = $this->quoteCalculator->quote(
            $purchases['jitaPurchases'],
            $this->volumeByTypeId($purchases['jitaPurchases']),
            null !== $workshopSolarSystemId,
            $courierHub,
            $hasJitaPurchases ? $this->jumpDistances->jumpsBetween(EveConstants::JITA_SOLAR_SYSTEM_ID, $courierHub->solarSystemId) : null,
        );

        $read = null !== $link || $canLink
            ? $this->contractReader->read($owner, $project->getCreatedAt())
            : null;
        $linked = null !== $link && null !== $read ? $this->linkedContractOf($link, $read) : null;
        $lineState = null === $linked ? CourierContractState::NotShipped : $linked->state;

        return new CourierOverview(
            $quote,
            $hasJitaPurchases ? EveConstants::JITA_SOLAR_SYSTEM_ID : null,
            $hasJitaPurchases ? $this->solarSystemNameOf(EveConstants::JITA_SOLAR_SYSTEM_ID) : null,
            $courierHub->solarSystemId,
            $this->solarSystemNameOf($courierHub->solarSystemId),
            $workshopSolarSystemId,
            null === $workshopSolarSystemId ? null : $this->solarSystemNameOf($workshopSolarSystemId),
            $canLink,
            $linked,
            array_map(
                static fn (array $line): CourierLine => new CourierLine($line['bomLineId'], $line['typeId'], $line['purchasedQuantity'], $lineState),
                $purchases['lines'],
            ),
            $canLink && null !== $read ? $this->contractReader->candidatesIn($read, $quote) : [],
            $this->otherOriginLinesOf($purchases['otherOriginQuantityBySystemAndTypeId']),
            $this->unknownOriginLinesOf($purchases['unknownOriginQuantityByTypeId']),
        );
    }

    /**
     * @return array{
     *     jitaPurchases: list<array{typeId: int, quantity: int, totalPrice: float}>,
     *     otherOriginQuantityBySystemAndTypeId: array<int, array<int, int>>,
     *     unknownOriginQuantityByTypeId: array<int, int>,
     *     lines: list<array{bomLineId: string, typeId: int, purchasedQuantity: int}>
     * }
     */
    private function purchasesOf(IndustryProject $project, ?int $workshopSolarSystemId): array
    {
        $jitaPurchases = [];
        $otherOriginQuantityBySystemAndTypeId = [];
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
                if (EveConstants::JITA_SOLAR_SYSTEM_ID !== $originSolarSystemId) {
                    $otherOriginQuantityBySystemAndTypeId[$originSolarSystemId][$purchase->getTypeId()] ??= 0;
                    $otherOriginQuantityBySystemAndTypeId[$originSolarSystemId][$purchase->getTypeId()] += $purchase->getQuantity();
                    continue;
                }
                $jitaPurchases[] = ['typeId' => $purchase->getTypeId(), 'quantity' => $purchase->getQuantity(), 'totalPrice' => $purchase->getTotalPrice()];
                $shippedQuantity += $purchase->getQuantity();
            }
            if ($shippedQuantity > 0) {
                $lines[] = ['bomLineId' => $bomLine->getId()->toRfc4122(), 'typeId' => $bomLine->getTypeId(), 'purchasedQuantity' => $shippedQuantity];
            }
        }

        return [
            'jitaPurchases' => $jitaPurchases,
            'otherOriginQuantityBySystemAndTypeId' => $otherOriginQuantityBySystemAndTypeId,
            'unknownOriginQuantityByTypeId' => $unknownOriginQuantityByTypeId,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<int, array<int, int>> $quantityBySystemAndTypeId
     *
     * @return list<CourierOtherOriginLine>
     */
    private function otherOriginLinesOf(array $quantityBySystemAndTypeId): array
    {
        ksort($quantityBySystemAndTypeId);
        $lines = [];
        foreach ($quantityBySystemAndTypeId as $solarSystemId => $quantityByTypeId) {
            ksort($quantityByTypeId);
            $solarSystemName = $this->solarSystemNameOf($solarSystemId);
            foreach ($quantityByTypeId as $typeId => $quantity) {
                $lines[] = new CourierOtherOriginLine($solarSystemId, $solarSystemName, $typeId, $quantity);
            }
        }

        return $lines;
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
