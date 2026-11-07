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
    ) {
    }

    public function build(IndustryProject $project, User $viewer): CourierOverview
    {
        $owner = $project->getOwner();
        $canLink = $viewer === $owner;
        $link = $this->linkRepository->findOneByProject($project);

        $purchases = $this->purchasesOf($project);
        $workshopSolarSystemId = $this->projectService->favoriteSystemsOf($owner)->manufacturingSystemId;
        $quote = $this->quoteCalculator->quote(
            $purchases['purchases'],
            $this->volumeByTypeId($purchases['purchases']),
            null !== $workshopSolarSystemId,
            null === $workshopSolarSystemId ? null : $this->jumpDistances->jumpsBetween(EveConstants::JITA_SOLAR_SYSTEM_ID, $workshopSolarSystemId),
        );

        $read = null !== $link || $canLink
            ? $this->contractReader->read($owner, $project->getCreatedAt())
            : null;
        $linked = null !== $link && null !== $read ? $this->linkedContractOf($link, $read) : null;
        $lineState = null === $linked ? CourierContractState::NotShipped : $linked->state;

        return new CourierOverview(
            $quote,
            EveConstants::JITA_SOLAR_SYSTEM_ID,
            $this->solarSystemRepository->findBySolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)?->getSolarSystemName(),
            $workshopSolarSystemId,
            null === $workshopSolarSystemId ? null : $this->solarSystemRepository->findBySolarSystemId($workshopSolarSystemId)?->getSolarSystemName(),
            $canLink,
            $linked,
            array_map(
                static fn (array $line): CourierLine => new CourierLine($line['bomLineId'], $line['typeId'], $line['purchasedQuantity'], $lineState),
                $purchases['lines'],
            ),
            $canLink && null !== $read ? $this->contractReader->candidatesIn($read, $quote) : [],
        );
    }

    /**
     * @return array{
     *     purchases: list<array{typeId: int, quantity: int, totalPrice: float}>,
     *     lines: list<array{bomLineId: string, typeId: int, purchasedQuantity: int}>
     * }
     */
    private function purchasesOf(IndustryProject $project): array
    {
        $purchases = [];
        $lines = [];
        foreach ($project->getBomItems() as $bomLine) {
            $purchasedQuantity = 0;
            foreach ($bomLine->getPurchases() as $purchase) {
                if ($purchase->getQuantity() <= 0) {
                    continue;
                }
                $purchases[] = ['typeId' => $purchase->getTypeId(), 'quantity' => $purchase->getQuantity(), 'totalPrice' => $purchase->getTotalPrice()];
                $purchasedQuantity += $purchase->getQuantity();
            }
            if ($purchasedQuantity > 0) {
                $lines[] = ['bomLineId' => $bomLine->getId()->toRfc4122(), 'typeId' => $bomLine->getTypeId(), 'purchasedQuantity' => $purchasedQuantity];
            }
        }

        return ['purchases' => $purchases, 'lines' => $lines];
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
