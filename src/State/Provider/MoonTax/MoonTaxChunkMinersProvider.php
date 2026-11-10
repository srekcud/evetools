<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MoonTax\MoonTaxChunkMinersResource;
use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\Entity\CorporationMiningExtraction;
use App\Entity\MoonObserverRecord;
use App\Entity\MoonTaxRefinerySetting;
use App\Enum\MoonChunkStatus;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\MoonObserverRecordRepository;
use App\Repository\MoonTaxRefinerySettingRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MoonTax\MoonChunkRecordWindow;
use App\Service\MoonTax\MoonTax;
use App\Service\MoonTax\MoonTaxAllianceResolver;
use App\Service\MoonTax\MoonTaxAssessor;
use App\Service\MoonTax\MoonTaxManagerAccess;
use App\Service\MoonTax\MoonTaxPeriod;
use App\Service\MoonTax\MoonTaxStatementReader;
use App\Service\MoonTax\MoonTaxStatementView;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MoonTaxChunkMinersResource>
 */
final readonly class MoonTaxChunkMinersProvider implements ProviderInterface
{
    private const string CHUNK_NOT_FOUND_MESSAGE = 'Moon chunk not found';
    private const string CHUNK_NOT_ARRIVED_MESSAGE = 'This moon chunk has not arrived yet';

    public function __construct(
        private Security $security,
        private MoonTaxManagerAccess $managerAccess,
        private CorporationMiningExtractionRepository $extractionRepository,
        private MoonTaxRefinerySettingRepository $settingRepository,
        private MoonObserverRecordRepository $recordRepository,
        private MoonChunkRecordWindow $recordWindow,
        private MoonTaxAssessor $assessor,
        private MoonTaxStatementReader $statementReader,
        private MoonTaxAllianceResolver $nameResolver,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MoonTaxChunkMinersResource
    {
        $corporationId = (int) $uriVariables['corporationId'];
        $this->managerAccess->assertManages(MiningOperationRequest::userOf($this->security), $corporationId);
        $chunk = $this->chunkOf($corporationId, (string) $uriVariables['id']);

        $resource = new MoonTaxChunkMinersResource();
        $resource->id = (string) $chunk->getId();
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($corporationId);

        $setting = $this->settingRepository->findOneBy(['corporationId' => $corporationId, 'structureId' => $chunk->getStructureId()]);
        if ($setting === null) {
            $resource->refineryOpen = false;
            $resource->miners = [];

            return $resource;
        }
        $resource->refineryOpen = true;
        $resource->miners = $this->miners($chunk, $setting);

        return $resource;
    }

    private function chunkOf(int $corporationId, string $chunkId): CorporationMiningExtraction
    {
        $chunk = $this->extractionRepository->find(UuidOrNotFound::of($chunkId, self::CHUNK_NOT_FOUND_MESSAGE));
        if ($chunk === null || $chunk->getCorporationId() !== $corporationId) {
            throw new NotFoundHttpException(self::CHUNK_NOT_FOUND_MESSAGE);
        }
        if (MoonChunkStatus::fromTimes($this->clock->now(), $chunk->getChunkArrivalTime(), $chunk->getNaturalDecayTime()) === MoonChunkStatus::Extracting) {
            throw new BadRequestHttpException(self::CHUNK_NOT_ARRIVED_MESSAGE);
        }

        return $chunk;
    }

    /**
     * @return list<array{characterId: int, characterName: ?string, chunkTax: ?float, unknownTypeIds: list<int>, statements: list<array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}>}>
     */
    private function miners(CorporationMiningExtraction $chunk, MoonTaxRefinerySetting $setting): array
    {
        $recordsByCharacter = [];
        foreach ($this->chunkRecords($chunk) as $record) {
            $recordsByCharacter[$record->getCharacterId()][] = $record;
        }
        if ($recordsByCharacter === []) {
            return [];
        }

        $moonTaxByCharacter = $this->assessor->assessAll($recordsByCharacter, $setting);
        $names = $this->nameResolver->characterNames(array_keys($recordsByCharacter));
        $viewsByKey = $this->statementViewsByKey($chunk);

        $miners = [];
        foreach ($recordsByCharacter as $characterId => $records) {
            $miners[] = $this->minerView($characterId, $names[$characterId], $moonTaxByCharacter[$characterId], $this->statementsOf($characterId, $records, $setting, $viewsByKey));
        }
        usort($miners, $this->byDecreasingTaxThenName(...));

        return $miners;
    }

    /**
     * @return list<MoonObserverRecord>
     */
    private function chunkRecords(CorporationMiningExtraction $chunk): array
    {
        $firstDay = $chunk->getChunkArrivalTime()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $dayAfterLast = $chunk->getNaturalDecayTime()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day');
        $records = $this->recordRepository->findOfObserverBetween($chunk->getCorporationId(), $chunk->getStructureId(), $firstDay, $dayAfterLast, null);
        $chunksOfRefinery = $this->extractionRepository->findBy(['corporationId' => $chunk->getCorporationId(), 'structureId' => $chunk->getStructureId()]);

        return $this->recordWindow->recordsOf($chunk, $chunksOfRefinery, $records);
    }

    /**
     * @return array<string, MoonTaxStatementView>
     */
    private function statementViewsByKey(CorporationMiningExtraction $chunk): array
    {
        $views = [];
        foreach ($this->statementReader->ofCorporation($chunk->getCorporationId()) as $view) {
            if ($view->structureId === $chunk->getStructureId()) {
                $views[self::statementKey($view->characterId, $view->periodStart)] = $view;
            }
        }

        return $views;
    }

    /**
     * @param list<MoonObserverRecord>          $records
     * @param array<string, MoonTaxStatementView> $viewsByKey
     *
     * @return list<array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}>
     */
    private function statementsOf(int $characterId, array $records, MoonTaxRefinerySetting $setting, array $viewsByKey): array
    {
        $periods = [];
        foreach ($records as $record) {
            $period = MoonTaxPeriod::containing($setting->getPeriod(), $record->getLastUpdated());
            $periods[$period->start->getTimestamp()] = $period;
        }
        ksort($periods);

        return array_values(array_map(
            fn (MoonTaxPeriod $period): array => $this->statementView($period, $viewsByKey[self::statementKey($characterId, $period->start)] ?? null),
            $periods,
        ));
    }

    /**
     * @return array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}
     */
    private function statementView(MoonTaxPeriod $period, ?MoonTaxStatementView $view): array
    {
        return [
            'periodStart' => $period->start->format(\DateTimeInterface::ATOM),
            'periodEnd' => $period->end->format(\DateTimeInterface::ATOM),
            'reference' => $view?->reference,
            'status' => $view?->status->value,
            'tax' => $view?->tax,
            'paid' => $view?->paid,
            'remaining' => $view?->remaining,
        ];
    }

    /**
     * @param list<array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}> $statements
     *
     * @return array{characterId: int, characterName: ?string, chunkTax: ?float, unknownTypeIds: list<int>, statements: list<array{periodStart: string, periodEnd: string, reference: ?string, status: ?string, tax: ?float, paid: ?float, remaining: ?float}>}
     */
    private function minerView(int $characterId, ?string $name, MoonTax $moonTax, array $statements): array
    {
        return [
            'characterId' => $characterId,
            'characterName' => $name,
            'chunkTax' => $moonTax->tax,
            'unknownTypeIds' => $moonTax->unknownTypeIds,
            'statements' => $statements,
        ];
    }

    /**
     * @param array{characterId: int, characterName: ?string, chunkTax: ?float} $left
     * @param array{characterId: int, characterName: ?string, chunkTax: ?float} $right
     */
    private function byDecreasingTaxThenName(array $left, array $right): int
    {
        $unknownLast = ($left['chunkTax'] === null) <=> ($right['chunkTax'] === null);
        if ($unknownLast !== 0) {
            return $unknownLast;
        }
        if ($left['chunkTax'] !== null && $right['chunkTax'] !== null && $left['chunkTax'] !== $right['chunkTax']) {
            return $right['chunkTax'] <=> $left['chunkTax'];
        }

        return [$left['characterName'] === null, $left['characterName'], $left['characterId']]
            <=> [$right['characterName'] === null, $right['characterName'], $right['characterId']];
    }

    private static function statementKey(int $characterId, \DateTimeImmutable $periodStart): string
    {
        return sprintf('%d-%d', $characterId, $periodStart->getTimestamp());
    }
}
