<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningSiteScan;
use App\Entity\Sde\InvType;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningHaulerPlanUnknownReason;
use App\Enum\MiningOperationStatus;
use App\Exception\BoostShipCapacityMissingFromSdeException;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Repository\Sde\InvTypeRepository;
use Psr\Clock\ClockInterface;

final readonly class MiningHaulerPlanReader
{
    private const string PRESENT_TYPE = 'present';
    private const string COMPRESSED_NAME_PREFIX = 'Compressed ';

    public function __construct(
        private ClockInterface $clock,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MinedSinceStartReader $minedSinceStart,
        private InvTypeRepository $typeRepository,
        private MiningOperationCompressionReport $compressionReport,
        private MiningBoostShipCapacities $capacities,
        private MiningFleetStorageShareCalculator $shareCalculator,
        private MiningHaulerPlanner $planner,
    ) {
    }

    public function read(MiningOperation $operation): MiningHaulerPlanReport
    {
        $boostShip = $operation->getBoostShip();
        $recipient = $operation->getFleetStorageRecipient();

        if (MiningOperationStatus::InProgress !== $operation->getStatus()) {
            return $this->unknown($operation, MiningHaulerPlanUnknownReason::OperationNotRunning);
        }
        if (null === $boostShip) {
            return $this->unknown($operation, MiningHaulerPlanUnknownReason::NoBoostShip);
        }
        if (null === $recipient) {
            return $this->unknown($operation, MiningHaulerPlanUnknownReason::NoFleetStorageRecipient);
        }

        $sites = $this->siteRepository->findActiveOfOperation($operation);
        $site = [] === $sites ? null : $sites[array_key_last($sites)];
        if (null === $site) {
            return $this->unknown($operation, MiningHaulerPlanUnknownReason::NoActiveSite);
        }

        $scan = $this->scanRepository->findLastOf($site);
        $unitsPerMinuteByTypeId = null === $scan ? [] : $this->unitsPerMinuteOf($scan);
        if ([] === $unitsPerMinuteByTypeId) {
            return $this->unknown($operation, MiningHaulerPlanUnknownReason::SiteRateUnknown);
        }

        $share = $this->shareCalculator->calculate($this->contributionsOf($operation, $recipient));
        if (null !== $share->unknownReason) {
            return $this->unknown($operation, $share->unknownReason);
        }

        try {
            $capacity = $this->capacities->of($boostShip);
        } catch (BoostShipCapacityMissingFromSdeException) {
            $capacity = null;
        }

        $emptiedAt = $operation->getFleetHangarEmptiedAt();
        $plan = $this->planner->plan(
            $capacity?->hangarM3,
            $capacity?->holdM3,
            $share->share ?? throw new \LogicException('A known share carries its ratio'),
            $this->ratesOf($operation, $unitsPerMinuteByTypeId),
            $emptiedAt ?? $operation->getStartedAt() ?? throw new \LogicException('A running operation has a start date'),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $scan?->getTrendEta(),
        );
        if (null !== $plan->unknownReason) {
            return $this->unknown($operation, $plan->unknownReason);
        }

        return new MiningHaulerPlanReport(
            PersistedUuid::of($operation->getId()),
            null,
            $boostShip->name,
            $this->nameOf($recipient),
            $emptiedAt,
            null === $emptiedAt,
            $capacity?->hangarM3,
            $capacity?->holdM3,
            $share->share,
            $plan->inflowM3PerMinute,
            $plan->fillM3,
            $plan->fillRatio,
            $plan->fullInMinutes,
            $plan->isFull,
            $plan->transportM3PerMinute,
            $plan->toMoveM3,
            $plan->trips,
            $scan?->getTrendEta(),
            $plan->rawTypeIds,
            $this->contributorNamesOf($operation, $recipient),
        );
    }

    private function unknown(MiningOperation $operation, MiningHaulerPlanUnknownReason $reason): MiningHaulerPlanReport
    {
        $emptiedAt = $operation->getFleetHangarEmptiedAt();
        $recipient = $operation->getFleetStorageRecipient();

        return new MiningHaulerPlanReport(
            PersistedUuid::of($operation->getId()),
            $reason,
            $operation->getBoostShip()?->name,
            null === $recipient ? null : $this->nameOf($recipient),
            $emptiedAt,
            null === $emptiedAt,
        );
    }

    /**
     * @return array<int, float>
     */
    private function unitsPerMinuteOf(MiningSiteScan $scan): array
    {
        $unitsPerMinute = [];
        foreach ($scan->getTypes() as $entry) {
            $trendRate = $entry['trendRate'] ?? null;
            if (self::PRESENT_TYPE === $entry['status'] && (\is_int($trendRate) || \is_float($trendRate)) && $trendRate > 0) {
                $unitsPerMinute[(int) $entry['typeId']] = (float) $trendRate;
            }
        }

        return $unitsPerMinute;
    }

    /**
     * @param array<int, float> $unitsPerMinuteByTypeId
     *
     * @return list<MiningHaulerTypeRate>
     */
    private function ratesOf(MiningOperation $operation, array $unitsPerMinuteByTypeId): array
    {
        $typeIds = array_keys($unitsPerMinuteByTypeId);
        $types = $this->typeRepository->findByTypeIds($typeIds);
        $compressedTypes = $this->typeRepository->findByNames(array_values(array_map(
            static fn (InvType $type): string => self::COMPRESSED_NAME_PREFIX.$type->getTypeName(),
            $types,
        )));
        $verdicts = $this->verdictsOf($operation, $typeIds);

        $rates = [];
        foreach ($unitsPerMinuteByTypeId as $typeId => $unitsPerMinute) {
            $type = $types[$typeId] ?? null;
            $compressedType = null === $type ? null : ($compressedTypes[self::COMPRESSED_NAME_PREFIX.$type->getTypeName()] ?? null);
            $compressible = $verdicts[$typeId] ?? null;
            $rates[] = new MiningHaulerTypeRate(
                $typeId,
                $unitsPerMinute,
                $type?->getVolume(),
                false === $compressible ? $type?->getVolume() : $compressedType?->getVolume(),
                $compressible,
            );
        }

        return $rates;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, ?bool>
     */
    private function verdictsOf(MiningOperation $operation, array $typeIds): array
    {
        $report = $this->compressionReport->of($operation, $typeIds);
        \assert(\is_array($report['types']));

        $verdicts = [];
        foreach ($report['types'] as $verdict) {
            \assert(\is_array($verdict));
            $verdicts[(int) $verdict['typeId']] = $verdict['compressible'] ?? null;
        }

        return $verdicts;
    }

    /**
     * @return list<MiningFleetStorageContribution>
     */
    private function contributionsOf(MiningOperation $operation, MiningOperationMember $recipient): array
    {
        return array_map(
            fn (MiningOperationMember $member): MiningFleetStorageContribution => new MiningFleetStorageContribution(
                $this->minedSinceStartBy($operation, $member),
                $member->getFleetStorageDelivery(),
                $member->getFleetStorageQuantities(),
                $member === $recipient,
            ),
            $operation->acceptedMembers(),
        );
    }

    /**
     * @return array<int, int>|null
     */
    private function minedSinceStartBy(MiningOperation $operation, MiningOperationMember $member): ?array
    {
        $minedByTypeId = [];
        foreach ($member->getCharacters() as $enrolment) {
            $mined = $this->minedSinceStart->ofEnrolment($operation, $enrolment);
            if (null === $mined) {
                return null;
            }
            foreach ($mined->quantitiesByTypeId as $typeId => $quantity) {
                $minedByTypeId[$typeId] = ($minedByTypeId[$typeId] ?? 0) + $quantity;
            }
        }

        return $minedByTypeId;
    }

    /**
     * @return list<string>
     */
    private function contributorNamesOf(MiningOperation $operation, MiningOperationMember $recipient): array
    {
        $names = [];
        foreach ($operation->acceptedMembers() as $member) {
            $name = $this->nameOf($member);
            if ($member !== $recipient && MiningFleetStorageDelivery::None !== $member->getFleetStorageDelivery() && null !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function nameOf(MiningOperationMember $member): ?string
    {
        return $member->getUser()->getMainCharacter()?->getName();
    }
}
