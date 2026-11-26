<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use Symfony\Component\Clock\ClockInterface;

class PlanetaryExtractionBalanceCalculator
{
    public const string ADVICE_DEFICIT = 'deficit';
    public const string ADVICE_SURPLUS = 'surplus';
    public const string ADVICE_BALANCED = 'balanced';

    private const float BALANCE_TOLERANCE = 1e-6;
    private const float FULL_RUN_RATE = 1.0;

    public function __construct(
        private readonly PlanetaryInputNeedsCalculator $inputNeeds,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calculate(PlanetaryColony $colony): array
    {
        $extractedTypeIds = $this->extractedTypeIds($colony);
        if ($extractedTypeIds === [] || !$this->hasFactory($colony)) {
            return [];
        }

        $rows = [];
        foreach ($this->inputNeeds->factoryNeedsOf($colony) as $need) {
            if (isset($extractedTypeIds[$need['typeId']])) {
                $rows[] = $this->row($colony, $need);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, true>
     */
    private function extractedTypeIds(PlanetaryColony $colony): array
    {
        $typeIds = [];
        foreach ($colony->getPins() as $pin) {
            $productTypeId = $pin->getExtractorProductTypeId();
            if ($productTypeId !== null) {
                $typeIds[$productTypeId] = true;
            }
        }

        return $typeIds;
    }

    private function hasFactory(PlanetaryColony $colony): bool
    {
        foreach ($colony->getPins() as $pin) {
            if ($pin->isFactory()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $need
     * @return array<string, mixed>
     */
    private function row(PlanetaryColony $colony, array $need): array
    {
        $factoryCount = $need['factoryCount'];
        $demanded = $need['dailyRequired'];
        $extracted = $need['dailyProducedLocally'];
        $balance = $extracted === null ? null : $extracted - $demanded;
        $headCount = $extracted === null ? null : $this->headCount($colony, $need['typeId']);

        return [
            'typeId' => $need['typeId'],
            'typeName' => $need['typeName'],
            'extractedPerDay' => $extracted,
            'demandedPerDay' => $demanded,
            'balancePerDay' => $balance,
            'runRate' => $extracted === null ? null : min(self::FULL_RUN_RATE, $extracted / $demanded),
            'factoryCount' => $factoryCount,
            'headCount' => $headCount,
            'advice' => $balance === null ? null : $this->advice($extracted, $demanded, $balance, $factoryCount, $headCount),
        ];
    }

    private function headCount(PlanetaryColony $colony, int $typeId): ?int
    {
        $now = $this->clock->now();
        $headCount = 0;
        foreach ($colony->getPins() as $pin) {
            if ($pin->getExtractorProductTypeId() !== $typeId || $this->isExpired($pin, $now)) {
                continue;
            }

            $heads = $pin->getExtractorNumHeads();
            if ($heads === null) {
                return null;
            }
            $headCount += $heads;
        }

        return $headCount;
    }

    private function isExpired(PlanetaryPin $pin, \DateTimeImmutable $now): bool
    {
        $expiry = $pin->getExpiryTime();

        return $expiry !== null && $expiry < $now;
    }

    /**
     * @return array{kind: string, heads: int|null, factories: int|null}
     */
    private function advice(float $extracted, float $demanded, float $balance, int $factoryCount, ?int $headCount): array
    {
        $demandPerFactory = $demanded / $factoryCount;

        if ($balance < -self::BALANCE_TOLERANCE) {
            $deficit = -$balance;

            return [
                'kind' => self::ADVICE_DEFICIT,
                'heads' => $this->headsToAdd($deficit, $extracted, $headCount),
                'factories' => $this->roundedUp($deficit / $demandPerFactory),
            ];
        }

        if ($balance >= $demandPerFactory - self::BALANCE_TOLERANCE) {
            return [
                'kind' => self::ADVICE_SURPLUS,
                'heads' => null,
                'factories' => (int) floor($balance / $demandPerFactory + self::BALANCE_TOLERANCE),
            ];
        }

        return ['kind' => self::ADVICE_BALANCED, 'heads' => null, 'factories' => null];
    }

    private function headsToAdd(float $deficit, float $extracted, ?int $headCount): ?int
    {
        if ($headCount === null || $headCount <= 0 || $extracted <= self::BALANCE_TOLERANCE) {
            return null;
        }

        return $this->roundedUp($deficit / ($extracted / $headCount));
    }

    private function roundedUp(float $quantity): int
    {
        return max(1, (int) ceil($quantity - self::BALANCE_TOLERANCE));
    }
}
