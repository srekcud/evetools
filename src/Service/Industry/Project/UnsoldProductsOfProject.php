<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectSale;

final readonly class UnsoldProductsOfProject
{
    private const string FINAL_JOB_GROUP = 'final';

    /**
     * @param iterable<IndustryProjectSale> $sales
     *
     * @return list<UnsoldProduct>
     */
    public function of(IndustryProject $project, iterable $sales): array
    {
        $toSellByTypeId = $this->toSellByTypeId($project);
        if ($toSellByTypeId === []) {
            return [];
        }

        $soldByTypeId = [];
        $latestSaleByTypeId = [];
        foreach ($sales as $sale) {
            $typeId = $sale->getTypeId();
            $soldByTypeId[$typeId] = ($soldByTypeId[$typeId] ?? 0) + $sale->getQuantity();
            $latest = $latestSaleByTypeId[$typeId] ?? null;
            if ($latest === null || $this->isMoreRecent($sale, $latest)) {
                $latestSaleByTypeId[$typeId] = $sale;
            }
        }

        $unsoldProducts = [];
        foreach ($toSellByTypeId as $typeId => $toSell) {
            $unsoldQuantity = $toSell['required'] - ($soldByTypeId[$typeId] ?? 0);
            if ($unsoldQuantity <= 0) {
                continue;
            }

            $unsoldProducts[] = new UnsoldProduct(
                $typeId,
                $toSell['typeName'],
                $unsoldQuantity,
                isset($latestSaleByTypeId[$typeId]) ? $latestSaleByTypeId[$typeId]->getUnitPrice() : null,
            );
        }

        return $unsoldProducts;
    }

    /**
     * @return array<int, array{typeName: string, required: int}>
     */
    private function toSellByTypeId(IndustryProject $project): array
    {
        $toSellByTypeId = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isJob() || $line->getJobGroup() !== self::FINAL_JOB_GROUP) {
                continue;
            }

            $typeId = $line->getTypeId();
            $toSellByTypeId[$typeId] = [
                'typeName' => $toSellByTypeId[$typeId]['typeName'] ?? $line->getTypeName(),
                'required' => ($toSellByTypeId[$typeId]['required'] ?? 0) + $line->getRequiredQuantity(),
            ];
        }

        return $toSellByTypeId;
    }

    private function isMoreRecent(IndustryProjectSale $candidate, IndustryProjectSale $reference): bool
    {
        $bySoldAt = $candidate->getSoldAt() <=> $reference->getSoldAt();

        return $bySoldAt !== 0 ? $bySoldAt > 0 : $candidate->getCreatedAt() > $reference->getCreatedAt();
    }
}
