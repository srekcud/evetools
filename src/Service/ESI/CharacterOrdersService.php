<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\CharacterSellOrder;
use App\Entity\Character;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;

class CharacterOrdersService
{
    private const int INCOMPLETE_ROW_STATUS_CODE = 502;

    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /**
     * @return list<CharacterSellOrder>
     *
     * @throws EveAuthRequiredException
     * @throws EsiApiException
     */
    public function getSellOrders(Character $character): array
    {
        $token = $character->getEveToken() ?? throw new EveAuthRequiredException((string) $character->getEveCharacterId());
        $endpoint = '/characters/' . $character->getEveCharacterId() . '/orders/';

        $sellOrders = [];
        foreach ($this->esiClient->getPaginated($endpoint, $token) as $row) {
            if (!\is_array($row)) {
                throw $this->incompleteRow('row', $endpoint);
            }
            if (($row['is_buy_order'] ?? false) === true) {
                continue;
            }
            $sellOrders[] = $this->toOrder($row, $endpoint);
        }

        return $sellOrders;
    }

    /** @param array<mixed> $row */
    private function toOrder(array $row, string $endpoint): CharacterSellOrder
    {
        return new CharacterSellOrder(
            $this->integerField($row, 'order_id', $endpoint),
            $this->integerField($row, 'type_id', $endpoint),
            $this->integerField($row, 'location_id', $endpoint),
            $this->floatField($row, 'price', $endpoint),
            $this->integerField($row, 'volume_remain', $endpoint),
            $this->integerField($row, 'volume_total', $endpoint),
            $this->dateField($row, 'issued', $endpoint),
        );
    }

    /** @param array<mixed> $row */
    private function integerField(array $row, string $field, string $endpoint): int
    {
        $value = $row[$field] ?? null;

        return \is_int($value) ? $value : throw $this->incompleteRow($field, $endpoint);
    }

    /** @param array<mixed> $row */
    private function floatField(array $row, string $field, string $endpoint): float
    {
        $value = $row[$field] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : throw $this->incompleteRow($field, $endpoint);
    }

    /** @param array<mixed> $row */
    private function dateField(array $row, string $field, string $endpoint): \DateTimeImmutable
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value)) {
            throw $this->incompleteRow($field, $endpoint);
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw $this->incompleteRow($field, $endpoint);
        }
    }

    private function incompleteRow(string $field, string $endpoint): EsiApiException
    {
        return EsiApiException::fromResponse(
            self::INCOMPLETE_ROW_STATUS_CODE,
            \sprintf('ESI character order row has a missing or invalid "%s"', $field),
            $endpoint,
        );
    }
}
