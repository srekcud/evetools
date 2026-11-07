<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

final readonly class CourierContractReader
{
    public const int MAX_CANDIDATES = 20;

    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
    ) {
    }

    public function read(User $owner, \DateTimeImmutable $issuedAfter): CourierContractsRead
    {
        return new CourierContractsRead([], []);
    }

    /**
     * @return list<CourierCandidate>
     */
    public function candidatesOf(User $owner, \DateTimeImmutable $issuedAfter, CourierQuote $quote): array
    {
        return [];
    }
}
