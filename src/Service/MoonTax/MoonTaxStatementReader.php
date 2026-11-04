<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxRefinerySetting;
use App\Entity\MoonTaxStatement;
use App\Enum\MoonTaxStatus;
use App\Repository\CorporationWalletDonationRepository;
use App\Repository\MoonObserverRecordRepository;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Repository\MoonTaxStatementRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class MoonTaxStatementReader
{
    public function __construct(
        private MoonTaxStatementRepository $statementRepository,
        private CorporationWalletDonationRepository $donationRepository,
        private MoonTaxRefinerySettingRepository $settingRepository,
        private MoonObserverRecordRepository $recordRepository,
        private MoonTaxSettlement $settlement,
        private MoonTaxAssessor $assessor,
        private MoonTaxAllianceResolver $allianceResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<MoonTaxStatementView>
     */
    public function ofCorporation(int $corporationId): array
    {
        return $this->compose(
            $this->statementRepository->findOfCorporation($corporationId),
            $this->donationRepository->findOfCorporation($corporationId),
            $this->settingRepository->findOpenOfCorporation($corporationId),
            null,
        );
    }

    /**
     * @param non-empty-list<int> $characterIds
     *
     * @return list<MoonTaxStatementView>
     */
    public function ofCharacters(array $characterIds): array
    {
        return $this->compose(
            $this->statementRepository->findOfCharacters($characterIds),
            $this->donationRepository->findForPayers($characterIds),
            $this->settingRepository->findOpen(),
            $characterIds,
        );
    }

    public function viewOfStatement(MoonTaxStatement $statement): MoonTaxStatementView
    {
        foreach ($this->ofCorporation($statement->getCorporationId()) as $view) {
            if ($view->id === (string) $statement->getId()) {
                return $view;
            }
        }

        throw new \LogicException(sprintf('Moon tax statement %s is missing from its corporation statements', $statement->getId()));
    }

    /**
     * @param list<MoonTaxStatement>          $statements
     * @param list<CorporationWalletDonation> $donations
     * @param list<MoonTaxRefinerySetting>    $openSettings
     * @param list<int>|null                  $characterIds
     *
     * @return list<MoonTaxStatementView>
     */
    private function compose(array $statements, array $donations, array $openSettings, ?array $characterIds): array
    {
        $now = $this->clock->now();
        $settlementLines = $this->settlement->settle($donations, $statements, $now);

        $views = [];
        $frozenKeys = [];
        foreach ($statements as $statement) {
            $line = $settlementLines[$statement->getReference()];
            $frozenKeys[MoonTaxStatementRepository::key($statement->getStructureId(), $statement->getPeriodStart(), $statement->getCharacterId())] = true;
            $views[] = new MoonTaxStatementView(
                (string) $statement->getId(),
                $statement->getCorporationId(),
                $statement->getStructureId(),
                $statement->getCharacterId(),
                $statement->getPeriodStart(),
                $statement->getPeriodEnd(),
                $statement->getReference(),
                $statement->getTax(),
                $line->paid,
                $line->remaining,
                $line->status,
                $statement->getUnknownTypeIds(),
                $statement->isOutsideAlliance(),
            );
        }

        foreach ($openSettings as $setting) {
            array_push($views, ...$this->provisionalViews($setting, $characterIds, $frozenKeys, $now));
        }

        usort($views, static fn (MoonTaxStatementView $left, MoonTaxStatementView $right): int => [$left->periodStart, $left->characterId, $left->structureId] <=> [$right->periodStart, $right->characterId, $right->structureId]);

        return $views;
    }

    /**
     * @param list<int>|null      $characterIds
     * @param array<string, true> $frozenKeys
     *
     * @return list<MoonTaxStatementView>
     */
    private function provisionalViews(MoonTaxRefinerySetting $setting, ?array $characterIds, array $frozenKeys, \DateTimeImmutable $now): array
    {
        $period = MoonTaxPeriod::containing($setting->getPeriod(), $now);
        $recordsByCharacter = [];
        foreach ($this->recordRepository->findOfObserverBetween($setting->getCorporationId(), $setting->getStructureId(), $period->start, $period->end, $characterIds) as $record) {
            if (!isset($frozenKeys[MoonTaxStatementRepository::key($setting->getStructureId(), $period->start, $record->getCharacterId())])) {
                $recordsByCharacter[$record->getCharacterId()][] = $record;
            }
        }

        $moonTaxByCharacter = $this->assessor->assessAll($recordsByCharacter, $setting);
        $views = [];
        foreach ($recordsByCharacter as $character => $records) {
            $moonTax = $moonTaxByCharacter[$character];
            $recordedCorporationId = $this->assessor->latestRecordedCorporationId($records);
            $views[] = new MoonTaxStatementView(
                null,
                $setting->getCorporationId(),
                $setting->getStructureId(),
                $character,
                $period->start,
                $period->end,
                null,
                $moonTax->tax,
                0.0,
                $moonTax->tax,
                MoonTaxStatus::Provisional,
                $moonTax->unknownTypeIds,
                $this->allianceResolver->isOutsideAlliance($setting->getCorporationId(), $recordedCorporationId),
            );
        }

        return $views;
    }
}
