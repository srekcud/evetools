<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\BlueprintDto;
use App\Entity\Character;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;

class BlueprintsService
{
    private const int INCOMPLETE_ROW_STATUS_CODE = 502;

    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /**
     * @return list<BlueprintDto>
     *
     * @throws EveAuthRequiredException
     * @throws EsiApiException
     */
    public function getCharacterBlueprints(Character $character): array
    {
        return $this->read('/characters/' . $character->getEveCharacterId() . '/blueprints/', $character);
    }

    /**
     * @return list<BlueprintDto>
     *
     * @throws EveAuthRequiredException
     * @throws EsiApiException
     */
    public function getCorporationBlueprints(Character $character): array
    {
        return $this->read('/corporations/' . $character->getCorporationId() . '/blueprints/', $character);
    }

    /** @return list<BlueprintDto> */
    private function read(string $endpoint, Character $character): array
    {
        $token = $character->getEveToken() ?? throw new EveAuthRequiredException((string) $character->getEveCharacterId());

        return array_map(
            fn (mixed $row): BlueprintDto => $this->toDto($row, $endpoint),
            array_values($this->esiClient->getPaginated($endpoint, $token)),
        );
    }

    private function toDto(mixed $row, string $endpoint): BlueprintDto
    {
        if (!is_array($row)) {
            throw $this->incompleteRow('row', $endpoint);
        }

        return new BlueprintDto(
            $this->integerField($row, 'item_id', $endpoint),
            $this->integerField($row, 'type_id', $endpoint),
            $this->integerField($row, 'location_id', $endpoint),
            $this->stringField($row, 'location_flag', $endpoint),
            $this->integerField($row, 'quantity', $endpoint),
            $this->integerField($row, 'material_efficiency', $endpoint),
            $this->integerField($row, 'time_efficiency', $endpoint),
            $this->integerField($row, 'runs', $endpoint),
        );
    }

    /** @param array<mixed> $row */
    private function integerField(array $row, string $field, string $endpoint): int
    {
        $value = $row[$field] ?? null;

        return is_int($value) ? $value : throw $this->incompleteRow($field, $endpoint);
    }

    /** @param array<mixed> $row */
    private function stringField(array $row, string $field, string $endpoint): string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : throw $this->incompleteRow($field, $endpoint);
    }

    private function incompleteRow(string $field, string $endpoint): EsiApiException
    {
        return EsiApiException::fromResponse(
            self::INCOMPLETE_ROW_STATUS_CODE,
            sprintf('ESI blueprint row has a missing or invalid "%s"', $field),
            $endpoint,
        );
    }
}
