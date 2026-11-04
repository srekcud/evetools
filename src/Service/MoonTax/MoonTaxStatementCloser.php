<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxStatement;
use App\Repository\MoonObserverRecordRepository;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Repository\MoonTaxStatementRepository;
use App\Repository\MoonTaxSyncStateRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class MoonTaxStatementCloser
{
    private const string LEDGER_CACHE_DELAY = '+1 hour';
    private const int MAX_REFERENCE_ATTEMPTS = 20;

    public function __construct(
        private MoonTaxSyncStateRepository $syncStateRepository,
        private MoonTaxRefinerySettingRepository $settingRepository,
        private MoonObserverRecordRepository $recordRepository,
        private MoonTaxStatementRepository $statementRepository,
        private MoonTaxAssessor $assessor,
        private MoonTaxReference $reference,
        private MoonTaxAllianceResolver $allianceResolver,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function close(int $corporationId): void
    {
        $ledgerReadAt = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId])?->getObserversSyncedAt();
        if ($ledgerReadAt === null) {
            return;
        }

        $frozenKeys = $this->statementRepository->findKeysOfCorporation($corporationId);
        foreach ($this->settingRepository->findOpenOfCorporation($corporationId) as $setting) {
            if (!$this->closeRefinery($setting, $ledgerReadAt, $frozenKeys)) {
                return;
            }
        }
    }

    /**
     * @param array<string, true> $frozenKeys
     *
     * @return bool false when a concurrent insertion stopped the closing, the next run closes the rest
     */
    private function closeRefinery(MoonTaxRefinerySetting $setting, \DateTimeImmutable $ledgerReadAt, array $frozenKeys): bool
    {
        $currentPeriodStart = MoonTaxPeriod::containing($setting->getPeriod(), $this->clock->now())->start;
        $records = $this->recordRepository->findOfObserverBefore($setting->getCorporationId(), $setting->getStructureId(), $currentPeriodStart);

        $recordsByGroup = [];
        $periodByGroup = [];
        foreach ($records as $record) {
            $period = MoonTaxPeriod::containing($setting->getPeriod(), $record->getLastUpdated());
            $group = MoonTaxStatementRepository::key($setting->getStructureId(), $period->start, $record->getCharacterId());
            if (isset($frozenKeys[$group]) || $period->end->modify(self::LEDGER_CACHE_DELAY) > $ledgerReadAt) {
                continue;
            }
            $recordsByGroup[$group][] = $record;
            $periodByGroup[$group] = $period;
        }
        if ($recordsByGroup === []) {
            return true;
        }

        uksort($recordsByGroup, static fn (string $left, string $right): int => [$periodByGroup[$left]->start, $recordsByGroup[$left][0]->getCharacterId()] <=> [$periodByGroup[$right]->start, $recordsByGroup[$right][0]->getCharacterId()]);
        $moonTaxByGroup = $this->assessor->assessAll($recordsByGroup, $setting);

        foreach ($recordsByGroup as $group => $groupRecords) {
            if (!$this->freeze($setting, $periodByGroup[$group], $groupRecords, $moonTaxByGroup[$group])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-list<MoonObserverRecord> $records
     */
    private function freeze(MoonTaxRefinerySetting $setting, MoonTaxPeriod $period, array $records, MoonTax $moonTax): bool
    {
        $recordedCorporationId = $this->assessor->latestRecordedCorporationId($records);

        $statement = (new MoonTaxStatement())
            ->setCorporationId($setting->getCorporationId())
            ->setStructureId($setting->getStructureId())
            ->setCharacterId($records[0]->getCharacterId())
            ->setPeriodStart($period->start)
            ->setPeriodEnd($period->end)
            ->setReference($this->freeReference($period))
            ->setLines($moonTax->lines)
            ->setTax($moonTax->tax)
            ->setUnknownTypeIds($moonTax->unknownTypeIds)
            ->setRecordedCorporationId($recordedCorporationId)
            ->setOutsideAlliance($this->allianceResolver->isOutsideAlliance($setting->getCorporationId(), $recordedCorporationId))
            ->setCreatedAt($this->clock->now());

        $this->entityManager->persist($statement);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->warning('Moon tax statement already frozen by a concurrent run, closing stopped', [
                'corporation_id' => $setting->getCorporationId(),
                'structure_id' => $setting->getStructureId(),
                'character_id' => $records[0]->getCharacterId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function freeReference(MoonTaxPeriod $period): string
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; ++$attempt) {
            $reference = $this->reference->forPeriod($period);
            if (!$this->statementRepository->referenceExists($reference)) {
                return $reference;
            }
        }

        throw new \RuntimeException(sprintf('No free moon tax reference found after %d draws', self::MAX_REFERENCE_ATTEMPTS));
    }
}
