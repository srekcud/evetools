<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\EveToken;

class OpenWindowService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    public function openMarketDetails(EveToken $token, int $typeId): void
    {
        $this->esiClient->postEmpty("/ui/openwindow/marketdetails/?type_id={$typeId}", $token);
    }

    public function openInformation(EveToken $token, int $targetId): void
    {
        $this->esiClient->postEmpty("/ui/openwindow/information/?target_id={$targetId}", $token);
    }

    public function openContract(EveToken $token, int $contractId): void
    {
        $this->esiClient->postEmpty("/ui/openwindow/contract/?contract_id={$contractId}", $token);
    }

    public function setWaypoint(EveToken $token, int $destinationId): void
    {
        $this->esiClient->postEmpty("/ui/autopilot/waypoint/?add_to_beginning=false&clear_other_waypoints=true&destination_id={$destinationId}", $token);
    }
}
