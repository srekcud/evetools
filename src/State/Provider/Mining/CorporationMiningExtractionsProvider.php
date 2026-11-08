<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\CorporationMiningExtractionsResource;
use App\Entity\CorporationMiningExtraction;
use App\Entity\CorporationMiningSyncState;
use App\Entity\User;
use App\Enum\CorporationMiningSyncFailure;
use App\Enum\MoonChunkStatus;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\CorporationMiningSyncStateRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MoonChunk\MoonChunkValueReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CorporationMiningExtractionsResource>
 */
final readonly class CorporationMiningExtractionsProvider implements ProviderInterface
{
    private const string HISTORY_WINDOW = '14 days';

    public function __construct(
        private Security $security,
        private CorporationMiningExtractionRepository $extractionRepository,
        private CorporationMiningSyncStateRepository $syncStateRepository,
        private MoonRefineryFollowRepository $followRepository,
        private ClockInterface $clock,
        private MoonChunkValueReader $chunkValueReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationMiningExtractionsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $now = $this->clock->now();
        $visibleExtractions = [];
        foreach ($user->getCharacterCorporationIds() as $corporationId) {
            $visibleExtractions[$corporationId] = $this->visibleExtractions($corporationId, $now);
        }
        $chunkValues = $this->chunkValueReader->read(array_merge(...array_values($visibleExtractions)), $now);

        $resource = new CorporationMiningExtractionsResource();
        $resource->corporations = array_map(
            fn (int $corporationId, array $extractions): array => $this->corporationEntry($corporationId, $now, $extractions, $chunkValues),
            array_keys($visibleExtractions),
            $visibleExtractions,
        );

        return $resource;
    }

    /**
     * @return list<CorporationMiningExtraction>
     */
    private function visibleExtractions(int $corporationId, \DateTimeImmutable $now): array
    {
        $oldestVisibleDecay = $now->modify('-' . self::HISTORY_WINDOW);

        return array_values(array_filter(
            $this->extractionRepository->findBy(['corporationId' => $corporationId], ['chunkArrivalTime' => 'ASC']),
            static fn (CorporationMiningExtraction $extraction): bool => $extraction->getNaturalDecayTime() >= $oldestVisibleDecay,
        ));
    }

    /**
     * @param list<CorporationMiningExtraction> $extractions
     * @param \SplObjectStorage<CorporationMiningExtraction, array<string, mixed>> $chunkValues
     *
     * @return array{corporationId: int, access: string, lastSuccessAt: ?string, extractions: list<array<string, mixed>>}
     */
    private function corporationEntry(int $corporationId, \DateTimeImmutable $now, array $extractions, \SplObjectStorage $chunkValues): array
    {
        $state = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId]);

        return [
            'corporationId' => $corporationId,
            'access' => $this->access($state),
            'lastSuccessAt' => $this->formatTime($state?->getLastSuccessAt()),
            'extractions' => array_map(
                fn (CorporationMiningExtraction $extraction): array => $this->extractionView($extraction, $now, $chunkValues),
                $extractions,
            ),
        ];
    }

    private function access(?CorporationMiningSyncState $state): string
    {
        if ($state === null) {
            return 'never_synced';
        }

        return match ($state->getLastFailure()) {
            null => 'ok',
            CorporationMiningSyncFailure::NoStationManagerToken => 'denied',
            CorporationMiningSyncFailure::EsiError => 'error',
        };
    }

    /**
     * @param \SplObjectStorage<CorporationMiningExtraction, array<string, mixed>> $chunkValues
     *
     * @return array<string, mixed>
     */
    private function extractionView(CorporationMiningExtraction $extraction, \DateTimeImmutable $now, \SplObjectStorage $chunkValues): array
    {
        return [
            'id' => $extraction->getId()?->toRfc4122(),
            'structureId' => $extraction->getStructureId(),
            'structureName' => $extraction->getStructureName(),
            'solarSystemId' => $extraction->getSolarSystemId(),
            'solarSystemName' => $extraction->getSolarSystemName(),
            'moonId' => $extraction->getMoonId(),
            'moonName' => $extraction->getMoonName(),
            'extractionStartTime' => $this->formatTime($extraction->getExtractionStartTime()),
            'chunkArrivalTime' => $this->formatTime($extraction->getChunkArrivalTime()),
            'naturalDecayTime' => $this->formatTime($extraction->getNaturalDecayTime()),
            'status' => MoonChunkStatus::fromTimes($now, $extraction->getChunkArrivalTime(), $extraction->getNaturalDecayTime())->value,
            'chunkValue' => $chunkValues[$extraction] ?? null,
        ];
    }

    private function formatTime(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
