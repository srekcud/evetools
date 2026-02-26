<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use Psr\Log\LoggerInterface;

class CorporationService
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCorporationInfo(int $corporationId): array
    {
        return $this->esiClient->getWithCache("/corporations/{$corporationId}/");
    }

    /**
     * @return array<int, string>
     */
    public function getDivisions(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $corporationId = $character->getCorporationId();

        try {
            $data = $this->esiClient->get("/corporations/{$corporationId}/divisions/", $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get corporation divisions', [
                'corporationId' => $corporationId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $divisions = [];

        // Hangar divisions
        foreach ($data['hangar'] ?? [] as $division) {
            $divisionNumber = $division['division'];
            $name = $division['name'] ?? "Division {$divisionNumber}";
            $divisions[$divisionNumber] = $name;
        }

        return $divisions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCorporationWithDivisions(Character $character): array
    {
        $corporationId = $character->getCorporationId();
        $info = $this->getCorporationInfo($corporationId);
        $divisions = $this->getDivisions($character);

        return [
            'id' => $corporationId,
            'name' => $info['name'],
            'ticker' => $info['ticker'],
            'member_count' => $info['member_count'],
            'alliance_id' => $info['alliance_id'] ?? null,
            'divisions' => $divisions,
        ];
    }
}
