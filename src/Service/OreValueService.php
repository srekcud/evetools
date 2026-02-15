<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Sde\InvTypeMaterialRepository;
use App\Repository\Sde\InvTypeRepository;

/**
 * Service to calculate ore values: raw, compressed, and reprocessed.
 */
class OreValueService
{
    // Compression ratio: 100 raw ore = 1 compressed ore
    private const COMPRESSION_RATIO = 100;

    // Default reprocessing yield (can be configured by user)
    private const DEFAULT_REPROCESS_YIELD = 0.78; // 78% yield with skills

    // Cache for compressed type mappings
    /** @var array<int, array{typeId: int, typeName: string}|null> */
    private array $compressedTypeCache = [];

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly InvTypeMaterialRepository $invTypeMaterialRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly ?StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * Get enriched price data for an ore type.
     *
     * @return array{
     *   rawUnitPrice: float|null,
     *   compressedTypeId: int|null,
     *   compressedTypeName: string|null,
     *   compressedUnitPrice: float|null,
     *   compressedEquivalentPrice: float|null,
     *   structureUnitPrice: float|null,
     *   structureCompressedUnitPrice: float|null,
     * }
     */
    public function getOrePrices(int $typeId, ?int $structureId = null): array
    {
        $result = [
            'rawUnitPrice' => null,
            'compressedTypeId' => null,
            'compressedTypeName' => null,
            'compressedUnitPrice' => null,
            'compressedEquivalentPrice' => null, // Price per raw unit based on compressed price
            'structureUnitPrice' => null,
            'structureCompressedUnitPrice' => null,
        ];

        // Get Jita price for raw ore
        $jitaPrices = $this->jitaMarketService->getPrices([$typeId]);
        $result['rawUnitPrice'] = $jitaPrices[$typeId] ?? null;

        // Find compressed variant
        $compressedType = $this->findCompressedVariant($typeId);
        if ($compressedType !== null) {
            $result['compressedTypeId'] = $compressedType['typeId'];
            $result['compressedTypeName'] = $compressedType['typeName'];

            // Get Jita price for compressed ore
            $compressedPrices = $this->jitaMarketService->getPrices([$compressedType['typeId']]);
            $result['compressedUnitPrice'] = $compressedPrices[$compressedType['typeId']] ?? null;

            // Calculate equivalent price per raw unit
            // If 1 compressed = 500 ISK, then 100 raw = 500 ISK, so 1 raw = 5 ISK
            if ($result['compressedUnitPrice'] !== null) {
                $result['compressedEquivalentPrice'] = $result['compressedUnitPrice'] / self::COMPRESSION_RATIO;
            }
        }

        // Get structure prices if structure ID provided
        if ($structureId !== null && $this->structureMarketService !== null) {
            $structurePrices = $this->structureMarketService->getLowestSellPrices($structureId, [$typeId]);
            $result['structureUnitPrice'] = $structurePrices[$typeId] ?? null;

            if ($compressedType !== null) {
                $structureCompressedPrices = $this->structureMarketService->getLowestSellPrices(
                    $structureId,
                    [$compressedType['typeId']]
                );
                $result['structureCompressedUnitPrice'] = $structureCompressedPrices[$compressedType['typeId']] ?? null;
            }
        }

        return $result;
    }

    /**
     * Get prices for multiple ore types in batch.
     *
     * @param int[] $typeIds
     * @param float $reprocessYield Reprocessing yield (0.0 to 1.0)
     * @param float $exportTax Export tax in ISK/m³ to subtract from Jita prices
     * @return array<int, array<string, mixed>> typeId => price data
     */
    public function getBatchOrePrices(array $typeIds, ?int $structureId = null, float $reprocessYield = self::DEFAULT_REPROCESS_YIELD, float $exportTax = 0.0): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $results = [];

        // Collect all type IDs we need (raw + compressed variants)
        $allTypeIds = $typeIds;
        $compressedMap = []; // rawTypeId => compressedTypeId

        foreach ($typeIds as $typeId) {
            $compressed = $this->findCompressedVariant($typeId);
            if ($compressed !== null) {
                $allTypeIds[] = $compressed['typeId'];
                $compressedMap[$typeId] = $compressed;
            }
        }

        // Get reprocess materials for all ore types
        $reprocessMaterials = $this->invTypeMaterialRepository->findByTypeIds($typeIds);

        // Collect all mineral type IDs needed for reprocess calculation
        $mineralTypeIds = [];
        foreach ($reprocessMaterials as $materials) {
            foreach ($materials as $material) {
                $mineralTypeIds[] = $material->getMaterialTypeId();
            }
        }
        $mineralTypeIds = array_unique($mineralTypeIds);

        // Add mineral type IDs to the price fetch
        $allTypeIds = array_unique(array_merge($allTypeIds, $mineralTypeIds));

        // Batch fetch Jita prices
        $jitaPrices = $this->jitaMarketService->getPrices($allTypeIds);

        // Batch fetch structure prices if needed
        $structurePrices = [];
        if ($structureId !== null && $this->structureMarketService !== null) {
            $structurePrices = $this->structureMarketService->getLowestSellPrices($structureId, $allTypeIds);
        }

        // Build results
        foreach ($typeIds as $typeId) {
            // Get the raw ore type for volume
            $rawType = $this->invTypeRepository->find($typeId);
            $rawVolume = $rawType?->getVolume() ?? 0.0;

            // Calculate export tax cost per unit (ISK/m³ * m³/unit = ISK/unit)
            $exportCostPerUnit = $exportTax * $rawVolume;

            // Raw Jita price with export tax applied
            $rawJitaPrice = $jitaPrices[$typeId] ?? null;
            $rawJitaPriceNet = null;
            if ($rawJitaPrice !== null) {
                $rawJitaPriceNet = max(0, $rawJitaPrice - $exportCostPerUnit);
            }

            $result = [
                'rawUnitPrice' => $rawJitaPriceNet,
                'compressedTypeId' => null,
                'compressedTypeName' => null,
                'compressedUnitPrice' => null,
                'compressedEquivalentPrice' => null,
                'structureUnitPrice' => $structurePrices[$typeId] ?? null,
                'structureCompressedUnitPrice' => null,
                'reprocessValue' => null,
                'structureReprocessValue' => null,
                'reprocessYield' => $reprocessYield,
            ];

            // Compressed prices
            if (isset($compressedMap[$typeId])) {
                $compressed = $compressedMap[$typeId];
                $result['compressedTypeId'] = $compressed['typeId'];
                $result['compressedTypeName'] = $compressed['typeName'];

                $compressedJitaPrice = $jitaPrices[$compressed['typeId']] ?? null;

                // Get compressed ore volume for export tax calculation
                $compressedType = $this->invTypeRepository->find($compressed['typeId']);
                $compressedVolume = $compressedType?->getVolume() ?? 0.0;
                $compressedExportCost = $exportTax * $compressedVolume;

                if ($compressedJitaPrice !== null) {
                    // Apply export tax to compressed price
                    $compressedJitaPriceNet = max(0, $compressedJitaPrice - $compressedExportCost);
                    $result['compressedUnitPrice'] = $compressedJitaPriceNet;
                    // Equivalent price per raw unit (1 compressed = 100 raw)
                    $result['compressedEquivalentPrice'] = $compressedJitaPriceNet / self::COMPRESSION_RATIO;
                }

                $result['structureCompressedUnitPrice'] = $structurePrices[$compressed['typeId']] ?? null;
            }

            // Reprocess value calculation
            if (isset($reprocessMaterials[$typeId])) {
                $portionSize = $rawType?->getPortionSize() ?? 100;

                // Jita reprocess value
                $jitaReprocessValue = 0.0;
                $hasAllJitaPrices = true;

                foreach ($reprocessMaterials[$typeId] as $material) {
                    $mineralPrice = $jitaPrices[$material->getMaterialTypeId()] ?? null;
                    if ($mineralPrice === null) {
                        $hasAllJitaPrices = false;
                        break;
                    }
                    $jitaReprocessValue += ($material->getQuantity() * $reprocessYield) * $mineralPrice;
                }

                if ($hasAllJitaPrices && $jitaReprocessValue > 0) {
                    $result['reprocessValue'] = $jitaReprocessValue / $portionSize;
                }

                // Structure reprocess value (if structure prices available)
                if (!empty($structurePrices)) {
                    $structureReprocessValue = 0.0;
                    $hasAllStructurePrices = true;

                    foreach ($reprocessMaterials[$typeId] as $material) {
                        $mineralPrice = $structurePrices[$material->getMaterialTypeId()] ?? null;
                        if ($mineralPrice === null) {
                            $hasAllStructurePrices = false;
                            break;
                        }
                        $structureReprocessValue += ($material->getQuantity() * $reprocessYield) * $mineralPrice;
                    }

                    if ($hasAllStructurePrices && $structureReprocessValue > 0) {
                        $result['structureReprocessValue'] = $structureReprocessValue / $portionSize;
                    }
                }
            }

            $results[$typeId] = $result;
        }

        return $results;
    }

    /**
     * Find the compressed variant of an ore type.
     *
     * @return array{typeId: int, typeName: string}|null
     */
    private function findCompressedVariant(int $rawTypeId): ?array
    {
        if (isset($this->compressedTypeCache[$rawTypeId])) {
            return $this->compressedTypeCache[$rawTypeId];
        }

        // Get the raw ore type
        $rawType = $this->invTypeRepository->find($rawTypeId);
        if ($rawType === null) {
            $this->compressedTypeCache[$rawTypeId] = null;
            return null;
        }

        $rawName = $rawType->getTypeName();

        // If already compressed, return null
        if (str_contains($rawName, 'Compressed')) {
            $this->compressedTypeCache[$rawTypeId] = null;
            return null;
        }

        // Search for "Compressed {OreNam}" variant
        $compressedName = 'Compressed ' . $rawName;
        $compressedType = $this->invTypeRepository->findOneBy(['typeName' => $compressedName]);

        if ($compressedType === null) {
            $this->compressedTypeCache[$rawTypeId] = null;
            return null;
        }

        $result = [
            'typeId' => $compressedType->getTypeId(),
            'typeName' => $compressedType->getTypeName(),
        ];

        $this->compressedTypeCache[$rawTypeId] = $result;
        return $result;
    }
}
