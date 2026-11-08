<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\User;
use App\Enum\MoonChunkStatus;
use App\Repository\CorporationMiningExtractionRepository;
use App\Service\UuidOrNotFound;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MiningOperationChunkResolver
{
    private const string CHUNK_NOT_FOUND_MESSAGE = 'Moon chunk not found';

    public function __construct(
        private CorporationMiningExtractionRepository $extractionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(User $owner, string $chunkId): CorporationMiningExtraction
    {
        $chunk = $this->extractionRepository->find(UuidOrNotFound::of($chunkId, self::CHUNK_NOT_FOUND_MESSAGE));
        $corporationId = $owner->getCorporationId();
        if ($chunk === null || $corporationId === null || $chunk->getCorporationId() !== $corporationId) {
            throw new NotFoundHttpException(self::CHUNK_NOT_FOUND_MESSAGE);
        }

        $status = MoonChunkStatus::fromTimes($this->clock->now(), $chunk->getChunkArrivalTime(), $chunk->getNaturalDecayTime());
        if ($status === MoonChunkStatus::Exploded) {
            throw new BadRequestHttpException('This moon chunk has already exploded');
        }

        return $chunk;
    }
}
