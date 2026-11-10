<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSite;
use App\Entity\User;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class MiningSiteMinersEstimateReader
{
    private const string SAMPLE_WINDOW = '-90 days';
    private const float SECONDS_PER_MINUTE = 60.0;

    public function __construct(
        private MiningSiteRepository $siteRepository,
        private MiningSiteScanRepository $scanRepository,
        private SiteTypeResolver $typeResolver,
        private SiteMinersCounter $minersCounter,
        private MinersNeededCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<TypeMinersEstimate>
     */
    public function read(User $user, \DateTimeImmutable $start): array
    {
        $sites = $this->siteRepository->findClosedLinkedToAnOperationOwnedBy($user);
        $firstScans = $this->scanRepository->findFirstVisibleOfSites($sites);
        $lastScans = $this->scanRepository->findLastVisibleOfSites($sites);
        $windowStart = $this->clock->now()->modify(self::SAMPLE_WINDOW);

        $samplesByTypeId = [];
        foreach ($sites as $site) {
            $key = $this->keyOf($site);
            $first = $firstScans[$key] ?? null;
            $last = $lastScans[$key] ?? null;
            $operation = $site->getOperation();
            if ($first === null || $last === null || $operation === null || $last->getTotalUnits() !== 0 || $first->getScannedAt() < $windowStart) {
                continue;
            }
            $typeId = $this->typeResolver->dominantTypeIdOf($first->getTypes());
            if ($typeId === null) {
                continue;
            }
            $samplesByTypeId[$typeId][] = new SiteLifespanSample(
                $site->getLabel(),
                $first->getScannedAt(),
                (float) ($last->getScannedAt()->getTimestamp() - $first->getScannedAt()->getTimestamp()) / self::SECONDS_PER_MINUTE,
                $this->minersCounter->countPresent($operation, $first->getScannedAt(), $last->getScannedAt()),
            );
        }

        $estimates = [];
        foreach ($samplesByTypeId as $typeId => $samples) {
            usort($samples, static fn (SiteLifespanSample $a, SiteLifespanSample $b): int => $b->firstScanAt <=> $a->firstScanAt);
            $estimates[] = new TypeMinersEstimate($typeId, $samples, $this->calculator->calculate($samples, $start));
        }
        usort($estimates, static fn (TypeMinersEstimate $a, TypeMinersEstimate $b): int => [\count($b->sites), $a->typeId] <=> [\count($a->sites), $b->typeId]);

        return $estimates;
    }

    private function keyOf(MiningSite $site): string
    {
        return ($site->getId() ?? throw new \LogicException('A persisted site has an id'))->toRfc4122();
    }
}
