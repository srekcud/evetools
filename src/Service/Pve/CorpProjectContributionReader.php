<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final readonly class CorpProjectContributionReader
{
    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }
}
