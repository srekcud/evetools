<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteScanRepository;
use App\Repository\Sde\InvTypeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class MiningSiteScanRecorder
{
    private const string PRESENT = 'present';
    private const string DEPLETED = 'depleted';
    private const string CLOSED_SITE_MESSAGE = 'This mining site is closed';
    private const string EMPTY_FIRST_SCAN_MESSAGE = 'The first scan of a site must list at least one ore type';
    private const string DUPLICATED_TYPE_MESSAGE = 'An ore type can only be listed once per scan';
    private const string UNKNOWN_TYPE_MESSAGE = 'Unknown ore type';
    private const string SAME_INSTANT_MESSAGE = 'A scan of this site already exists at this second';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MiningSiteScanRepository $scanRepository,
        private InvTypeRepository $invTypeRepository,
        private MiningSiteUnitPrices $unitPrices,
        private SiteDepletionEstimator $estimator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<ScanLine> $lines
     */
    public function record(MiningSite $site, array $lines, User $author): MiningSiteScan
    {
        return $this->entityManager->wrapInTransaction(fn (): MiningSiteScan => $this->recordInTransaction($site, $lines));
    }

    /**
     * @param list<ScanLine> $lines
     */
    private function recordInTransaction(MiningSite $site, array $lines): MiningSiteScan
    {
        $this->entityManager->refresh($site, LockMode::PESSIMISTIC_WRITE);
        if ($site->getStatus() === MiningSiteStatus::Closed) {
            throw new BadRequestHttpException(self::CLOSED_SITE_MESSAGE);
        }

        $previousScans = $this->scanRepository->findBySiteChronological($site);
        $unitsByTypeId = $this->unitsByTypeIdOf($lines);
        if ($unitsByTypeId === [] && $previousScans === []) {
            throw $this->refusal(self::EMPTY_FIRST_SCAN_MESSAGE, 'lines', []);
        }

        $scannedAt = \DateTimeImmutable::createFromTimestamp($this->clock->now()->getTimestamp());
        if ($this->scanRepository->existsAt($site, $scannedAt)) {
            throw $this->refusal(self::SAME_INSTANT_MESSAGE, 'scannedAt', $scannedAt->format(\DateTimeInterface::ATOM));
        }

        $typeIds = array_keys($unitsByTypeId);
        $volumes = $this->volumesOf($typeIds);
        $estimate = $this->estimator->estimate(
            [...array_map($this->sampleOf(...), $previousScans), new SiteScanSample($scannedAt, $unitsByTypeId)],
            $this->unitPrices->read($typeIds),
            $volumes,
        );

        $scan = (new MiningSiteScan())
            ->setSite($site)
            ->setScannedAt($scannedAt)
            ->setTotalUnits(array_sum($unitsByTypeId))
            ->setTotalVolumeM3($this->totalVolumeOf($estimate))
            ->setRemainingValue($estimate->remainingValue)
            ->setMissingPriceTypeIds($estimate->missingPriceTypeIds)
            ->setRecentEta($estimate->recentEta)
            ->setTrendEta($estimate->trendEta)
            ->setTypes(array_values(array_map($this->entryOf(...), $estimate->types)));
        $this->entityManager->persist($scan);

        if ($estimate->allDepleted) {
            $site->setStatus(MiningSiteStatus::Closed);
        }
        $this->entityManager->flush();

        return $scan;
    }

    /**
     * @param list<ScanLine> $lines
     *
     * @return array<int, int>
     */
    private function unitsByTypeIdOf(array $lines): array
    {
        $unitsByTypeId = [];
        foreach ($lines as $line) {
            if (array_key_exists($line->typeId, $unitsByTypeId)) {
                throw $this->refusal(self::DUPLICATED_TYPE_MESSAGE, 'lines', $line->typeId);
            }
            $unitsByTypeId[$line->typeId] = $line->units;
        }

        return $unitsByTypeId;
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, ?float>
     */
    private function volumesOf(array $typeIds): array
    {
        $types = $this->invTypeRepository->findByTypeIds($typeIds);

        $volumes = [];
        foreach ($typeIds as $typeId) {
            if (!array_key_exists($typeId, $types)) {
                throw $this->refusal(self::UNKNOWN_TYPE_MESSAGE, 'lines', $typeId);
            }
            $volumes[$typeId] = $types[$typeId]->getVolume();
        }

        return $volumes;
    }

    private function sampleOf(MiningSiteScan $scan): SiteScanSample
    {
        $unitsByTypeId = [];
        foreach ($scan->getTypes() as $entry) {
            if ($entry['status'] === self::PRESENT) {
                $unitsByTypeId[(int) $entry['typeId']] = (int) $entry['units'];
            }
        }

        return new SiteScanSample($scan->getScannedAt(), $unitsByTypeId);
    }

    private function totalVolumeOf(SiteEstimate $estimate): ?float
    {
        $total = 0.0;
        foreach ($estimate->types as $type) {
            if ($type->volumeM3 === null) {
                return null;
            }
            $total += $type->volumeM3;
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function entryOf(SiteTypeEstimate $type): array
    {
        return [
            'typeId' => $type->typeId,
            'status' => $type->isDepleted() ? self::DEPLETED : self::PRESENT,
            'units' => $type->units,
            'volumeM3' => $type->volumeM3,
            'unitPrice' => $type->unitPrice,
            'remainingValue' => $type->remainingValue,
            'recentRate' => $type->recentRate,
            'recentEta' => $this->isoOf($type->recentEta),
            'trendRate' => $type->trendRate,
            'trendEta' => $this->isoOf($type->trendEta),
            'maxDeviationUnits' => $type->maxDeviationUnits,
            'maxDeviationPercent' => $type->maxDeviationPercent,
            'etaUnknownReason' => $type->etaUnknownReason?->value,
            'depletedBetween' => $type->isDepleted() ? ['from' => $this->isoOf($type->lastPresentAt), 'to' => $this->isoOf($type->firstAbsentAt)] : null,
        ];
    }

    private function isoOf(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function refusal(string $message, string $propertyPath, mixed $invalidValue): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $invalidValue, $propertyPath, $invalidValue),
        ]));
    }
}
