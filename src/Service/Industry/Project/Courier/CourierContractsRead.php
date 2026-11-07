<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierContractsRead
{
    /**
     * @param list<CourierContract> $contracts
     * @param array<int, string>    $unreadableReasonByCharacterId
     */
    public function __construct(
        public array $contracts,
        public array $unreadableReasonByCharacterId,
    ) {
    }
}
