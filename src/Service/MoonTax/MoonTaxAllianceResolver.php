<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use Psr\Log\LoggerInterface;

final readonly class MoonTaxAllianceResolver
{
    private const string CHARACTER_CATEGORY = 'character';

    public function __construct(
        private EsiClient $esiClient,
        private LoggerInterface $logger,
    ) {
    }

    public function isOutsideAlliance(int $ownerCorporationId, int $recordedCorporationId): ?bool
    {
        if ($recordedCorporationId === $ownerCorporationId) {
            return false;
        }

        try {
            $ownerAllianceId = $this->allianceIdOf($ownerCorporationId);
            $recordedAllianceId = $this->allianceIdOf($recordedCorporationId);
        } catch (EsiApiException|\UnexpectedValueException $exception) {
            $this->logger->warning('Alliance of a corporation unreadable, the outside alliance flag stays unknown', [
                'owner_corporation_id' => $ownerCorporationId,
                'recorded_corporation_id' => $recordedCorporationId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($ownerAllianceId === null || $recordedAllianceId === null) {
            return true;
        }

        return $ownerAllianceId !== $recordedAllianceId;
    }

    /**
     * @param list<int> $characterIds
     *
     * @return array<int, ?string> keyed by character id, null when the name is unknown
     */
    public function characterNames(array $characterIds): array
    {
        $namesByCharacterId = array_fill_keys($characterIds, null);
        if ($characterIds === []) {
            return $namesByCharacterId;
        }

        try {
            $answer = $this->esiClient->post('/universe/names/', array_values(array_unique($characterIds)));
        } catch (EsiApiException $exception) {
            $this->logger->warning('Character names unreadable, they stay unknown', ['error' => $exception->getMessage()]);

            return $namesByCharacterId;
        }

        foreach ($answer as $entry) {
            if (is_array($entry) && ($entry['category'] ?? null) === self::CHARACTER_CATEGORY && is_int($entry['id'] ?? null) && is_string($entry['name'] ?? null) && array_key_exists($entry['id'], $namesByCharacterId)) {
                $namesByCharacterId[$entry['id']] = $entry['name'];
            }
        }

        return $namesByCharacterId;
    }

    private function allianceIdOf(int $corporationId): ?int
    {
        $allianceId = $this->esiClient->getWithCache(sprintf('/corporations/%d/', $corporationId))['alliance_id'] ?? null;
        if ($allianceId !== null && !is_int($allianceId)) {
            throw new \UnexpectedValueException(sprintf('ESI corporation %d has a non-integer alliance_id', $corporationId));
        }

        return $allianceId;
    }
}
