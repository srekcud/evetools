<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\User;
use App\Repository\CorporationMiningExtractionRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class MiningOperationChunkResolver
{
    public function __construct(
        private CorporationMiningExtractionRepository $extractionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(User $owner, string $chunkId): CorporationMiningExtraction
    {
        throw new \LogicException('Not implemented');
    }
}
