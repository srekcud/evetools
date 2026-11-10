<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\CorporationMiningExtractionsResource;
use App\Entity\CorporationMiningExtraction;
use App\Entity\CorporationMiningSyncState;
use App\Entity\MiningOperation;
use App\Entity\MoonRefineryFollow;
use App\Entity\User;
use App\Enum\CorporationMiningSyncFailure;
use App\Enum\MoonChunkStatus;
use App\Repository\CorporationMiningExtractionRepository;
use App\Repository\CorporationMiningSyncStateRepository;
use App\Repository\MiningOperationRepository;
use App\Repository\MoonRefineryFollowRepository;
use App\Service\MiningOperation\PersistedUuid;
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
        private MiningOperationRepository $operationRepository,
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

        $linkedOperations = $this->linkedOperationsByChunkId($user);

        $resource = new CorporationMiningExtractionsResource();
        $resource->corporations = array_map(
            fn (int $corporationId, array $extractions): array => $this->corporationEntry($user, $corporationId, $now, $extractions, $chunkValues, $linkedOperations),
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
     * @return array<string, non-empty-list<MiningOperation>>
     */
    private function linkedOperationsByChunkId(User $user): array
    {
        $linkedOperations = [];
        foreach ($this->operationRepository->findLinkedToChunksOf($user) as $linkedOperation) {
            $chunk = $linkedOperation->getChunk() ?? throw new \LogicException('An operation read as linked to a chunk has a chunk');
            $linkedOperations[PersistedUuid::of($chunk->getId())][] = $linkedOperation;
        }

        return $linkedOperations;
    }

    /**
     * @param list<CorporationMiningExtraction> $extractions
     * @param \SplObjectStorage<CorporationMiningExtraction, array<string, mixed>> $chunkValues
     * @param array<string, non-empty-list<MiningOperation>> $linkedOperations
     *
     * @return array{corporationId: int, access: string, lastSuccessAt: ?string, followedStructureIds: list<int>, extractions: list<array<string, mixed>>}
     */
    private function corporationEntry(User $user, int $corporationId, \DateTimeImmutable $now, array $extractions, \SplObjectStorage $chunkValues, array $linkedOperations): array
    {
        $state = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId]);

        return [
            'corporationId' => $corporationId,
            'access' => $this->access($state),
            'lastSuccessAt' => $this->formatTime($state?->getLastSuccessAt()),
            'followedStructureIds' => $this->followedStructureIds($user, $corporationId),
            'extractions' => array_map(
                fn (CorporationMiningExtraction $extraction): array => $this->extractionView($extraction, $now, $chunkValues, $linkedOperations),
                $extractions,
            ),
        ];
    }

    /**
     * @return list<int>
     */
    private function followedStructureIds(User $user, int $corporationId): array
    {
        return array_map(
            static fn (MoonRefineryFollow $follow): int => $follow->getStructureId(),
            $this->followRepository->findBy(['user' => $user, 'corporationId' => $corporationId]),
        );
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
     * @param array<string, non-empty-list<MiningOperation>> $linkedOperations
     *
     * @return array<string, mixed>
     */
    private function extractionView(CorporationMiningExtraction $extraction, \DateTimeImmutable $now, \SplObjectStorage $chunkValues, array $linkedOperations): array
    {
        $linkedToChunk = $linkedOperations[PersistedUuid::of($extraction->getId())] ?? [];
        $mostRecentLinked = $linkedToChunk[0] ?? null;

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
            'linkedOperation' => $mostRecentLinked === null ? null : [
                'id' => PersistedUuid::of($mostRecentLinked->getId()),
                'label' => $mostRecentLinked->getLabel(),
                'status' => $mostRecentLinked->getStatus()->value,
            ],
            'linkedOperationCount' => count($linkedToChunk),
        ];
    }

    private function formatTime(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
