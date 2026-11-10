<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningBoostShip;
use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningSeasonPeriod;
use App\Repository\MiningOperationRepository;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningSite\MiningSiteHistorySummarizer;
use Psr\Clock\ClockInterface;

final readonly class MiningSeasonReader
{
    private const int SECONDS_PER_MINUTE = 60;
    private const int MINIMUM_SCANS_TO_MEASURE = 2;

    public function __construct(
        private MiningOperationRepository $operationRepository,
        private MiningSeasonCalculator $calculator,
        private MiningOperationValuator $valuator,
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private MiningSiteHistorySummarizer $siteSummarizer,
        private ClockInterface $clock,
    ) {
    }

    public function read(User $user, MiningSeasonPeriod $period): MiningSeasonSummary
    {
        $corporationId = $user->getCorporationId();
        if ($corporationId === null) {
            return $this->calculator->summarise([]);
        }

        $days = $period->days();
        $endedSince = $days === null ? null : $this->clock->now()->modify(sprintf('-%d days', $days));

        $operations = $this->operationRepository->findOfSeason($corporationId, $endedSince);
        $seasonSites = $this->siteRepository->findOfOperations($operations);
        $siteFirstScans = $this->scanRepository->findFirstVisibleOfSites($seasonSites);
        $siteLastScans = $this->scanRepository->findLastVisibleOfSites($seasonSites);
        $siteScanCounts = $this->scanRepository->countVisibleOfSites($seasonSites);

        $sitesByOperation = [];
        foreach ($seasonSites as $site) {
            $operationId = ($site->getOperation()?->getId() ?? throw new \LogicException('A season site belongs to an operation'))->toRfc4122();
            $sitesByOperation[$operationId][] = $this->siteOf($site, $siteFirstScans, $siteLastScans, $siteScanCounts);
        }

        return $this->calculator->summarise(array_map(
            fn (MiningOperation $operation): MiningSeasonOperation => $this->operationOf($operation, $sitesByOperation[($operation->getId() ?? throw new \LogicException('A persisted operation has an id'))->toRfc4122()] ?? []),
            $operations,
        ));
    }

    /**
     * @param array<string, MiningSiteScan> $firstScans
     * @param array<string, MiningSiteScan> $lastScans
     * @param array<string, int>            $scanCounts
     */
    private function siteOf(MiningSite $site, array $firstScans, array $lastScans, array $scanCounts): MiningSeasonSite
    {
        $key = ($site->getId() ?? throw new \LogicException('A persisted site has an id'))->toRfc4122();
        $scansCount = $scanCounts[$key];
        if ($scansCount < self::MINIMUM_SCANS_TO_MEASURE) {
            return new MiningSeasonSite($site->getSolarSystemId(), $site->getLabel(), $scansCount, 0, null);
        }

        $history = $this->siteSummarizer->summarize(
            $firstScans[$key] ?? throw new \LogicException('A site with visible scans has a first scan'),
            $lastScans[$key] ?? throw new \LogicException('A site with visible scans has a last scan'),
        );

        return new MiningSeasonSite($site->getSolarSystemId(), $site->getLabel(), $scansCount, $history->observedSeconds, $history->extractedValue);
    }

    /**
     * @param list<MiningSeasonSite> $sites
     */
    private function operationOf(MiningOperation $operation, array $sites): MiningSeasonOperation
    {
        $startedAt = $operation->getStartedAt() ?? throw new \LogicException('An ended operation has a start');
        $endedAt = $operation->getEndedAt() ?? throw new \LogicException('An ended operation has an end');
        $isKnown = $operation->getSharesFrozenAt() !== null;

        $participants = [];
        foreach ($operation->acceptedMembers() as $member) {
            $presenceStartedAt = $this->earliestStartSnapshotOf($member);
            if ($presenceStartedAt === null) {
                continue;
            }
            $participants[] = new MiningSeasonParticipant(
                (string) $member->getUser()->getId(),
                $this->nameOf($operation, $member),
                max(0, $this->minutesBetween($presenceStartedAt, $member->getDepartedAt() ?? $endedAt)),
                $isKnown ? $this->frozenValueOf($operation, $member) : null,
            );
        }

        return new MiningSeasonOperation(
            $this->minutesBetween($startedAt, $endedAt),
            $isKnown ? array_sum(array_map(static fn (MiningFrozenMinedValue $frozen): int => (int) $frozen->getMinedValue(), $operation->getFrozenMinedValues()->toArray())) : null,
            $participants,
            $isKnown ? $this->charactersOf($operation, $endedAt) : [],
            $sites,
        );
    }

    /**
     * @return list<MiningSeasonCharacter>
     */
    private function charactersOf(MiningOperation $operation, \DateTimeImmutable $endedAt): array
    {
        $minedValues = [];
        foreach ($this->valuator->value($operation)->participants as $participant) {
            foreach ($participant->characters as $valuation) {
                $minedValues[$valuation->operationCharacter->getCharacter()->getEveCharacterId()] = $valuation->minedValue;
            }
        }

        $characters = [];
        foreach ($operation->acceptedMembers() as $member) {
            foreach ($member->getCharacters() as $enrolment) {
                $startedAt = $enrolment->snapshotOf(MiningLedgerSnapshotKind::Start)?->getTakenAt();
                if ($startedAt === null) {
                    continue;
                }
                $eveCharacterId = $enrolment->getCharacter()->getEveCharacterId();
                $shipTypeId = $enrolment->getShipTypeId();
                $isBooster = $shipTypeId !== null && in_array($shipTypeId, MiningBoostShip::values(), true);
                $characters[] = new MiningSeasonCharacter(
                    (string) $eveCharacterId,
                    $shipTypeId,
                    max(0, $this->minutesBetween($startedAt, $member->getDepartedAt() ?? $endedAt)),
                    $isBooster ? null : ($minedValues[$eveCharacterId] ?? null),
                    $isBooster,
                );
            }
        }

        return $characters;
    }

    private function earliestStartSnapshotOf(MiningOperationMember $member): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($member->getCharacters() as $enrolment) {
            $takenAt = $enrolment->snapshotOf(MiningLedgerSnapshotKind::Start)?->getTakenAt();
            if ($takenAt !== null && ($earliest === null || $takenAt < $earliest)) {
                $earliest = $takenAt;
            }
        }

        return $earliest;
    }

    private function nameOf(MiningOperation $operation, MiningOperationMember $member): string
    {
        foreach ($operation->getFrozenMinedValues() as $frozen) {
            if ($frozen->getMember() === $member && $frozen->getParticipantName() !== null) {
                return $frozen->getParticipantName();
            }
        }

        return $member->getUser()->getMainCharacter()?->getName() ?? throw new \LogicException('A participant has a main character');
    }

    private function frozenValueOf(MiningOperation $operation, MiningOperationMember $member): int
    {
        return array_sum(array_map(
            static fn (MiningFrozenMinedValue $frozen): int => (int) $frozen->getMinedValue(),
            array_filter($operation->getFrozenMinedValues()->toArray(), static fn (MiningFrozenMinedValue $frozen): bool => $frozen->getMember() === $member),
        ));
    }

    private function minutesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), self::SECONDS_PER_MINUTE);
    }
}
