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
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationMiningExtractionsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $now = $this->clock->now();
        $resource = new CorporationMiningExtractionsResource();
        $resource->corporations = array_map(
            fn (int $corporationId): array => $this->corporationEntry($corporationId, $now),
            $user->getCharacterCorporationIds(),
        );

        return $resource;
    }

    /**
     * @return array{corporationId: int, access: string, lastSuccessAt: ?string, extractions: list<array<string, int|string|null>>}
     */
    private function corporationEntry(int $corporationId, \DateTimeImmutable $now): array
    {
        $state = $this->syncStateRepository->findOneBy(['corporationId' => $corporationId]);
        $oldestVisibleDecay = $now->modify('-' . self::HISTORY_WINDOW);
        $extractions = array_filter(
            $this->extractionRepository->findBy(['corporationId' => $corporationId], ['chunkArrivalTime' => 'ASC']),
            static fn (CorporationMiningExtraction $extraction): bool => $extraction->getNaturalDecayTime() >= $oldestVisibleDecay,
        );

        return [
            'corporationId' => $corporationId,
            'access' => $this->access($state),
            'lastSuccessAt' => $this->formatTime($state?->getLastSuccessAt()),
            'extractions' => array_values(array_map(
                fn (CorporationMiningExtraction $extraction): array => $this->extractionView($extraction, $now),
                $extractions,
            )),
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
     * @return array<string, int|string|null>
     */
    private function extractionView(CorporationMiningExtraction $extraction, \DateTimeImmutable $now): array
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
        ];
    }

    private function formatTime(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
