<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Constant\EveConstants;
use App\Enum\TransferCarrierKind;

final class TransferRoute
{
    /**
     * @return list<TransferLeg>
     */
    public static function legs(int $fromSolarSystemId, int $toSolarSystemId, int $hubSolarSystemId): array
    {
        if (EveConstants::JITA_SOLAR_SYSTEM_ID === $fromSolarSystemId && EveConstants::JITA_SOLAR_SYSTEM_ID === $toSolarSystemId) {
            return [];
        }

        if ($fromSolarSystemId === $toSolarSystemId) {
            return [new TransferLeg(TransferCarrierKind::Pilot, $fromSolarSystemId, $toSolarSystemId)];
        }

        if (EveConstants::JITA_SOLAR_SYSTEM_ID === $fromSolarSystemId) {
            return self::withoutEmptyHops([
                new TransferLeg(TransferCarrierKind::Courier, $fromSolarSystemId, $hubSolarSystemId),
                new TransferLeg(TransferCarrierKind::JumpBridge, $hubSolarSystemId, $toSolarSystemId),
            ]);
        }

        if (EveConstants::JITA_SOLAR_SYSTEM_ID === $toSolarSystemId) {
            return self::withoutEmptyHops([
                new TransferLeg(TransferCarrierKind::JumpBridge, $fromSolarSystemId, $hubSolarSystemId),
                new TransferLeg(TransferCarrierKind::Courier, $hubSolarSystemId, $toSolarSystemId),
            ]);
        }

        return [new TransferLeg(TransferCarrierKind::JumpBridge, $fromSolarSystemId, $toSolarSystemId)];
    }

    /**
     * @param list<TransferLeg> $legs
     *
     * @return list<TransferLeg>
     */
    private static function withoutEmptyHops(array $legs): array
    {
        return array_values(array_filter($legs, static fn (TransferLeg $leg): bool => $leg->fromSolarSystemId !== $leg->toSolarSystemId));
    }
}
