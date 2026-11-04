<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxMineResource;
use App\ApiResource\MoonTax\MoonTaxStatementResource;
use App\Service\MoonTax\MoonTaxStatementView;

final readonly class MoonTaxResourceMapper
{
    public function statementToResource(MoonTaxStatementView $view, ?string $characterName): MoonTaxStatementResource
    {
        $resource = new MoonTaxStatementResource();
        $resource->id = $view->id;
        $resource->corporation = MoonTaxCorporationResource::identifiedBy($view->corporationId);
        $resource->corporationId = $view->corporationId;
        $resource->structureId = $view->structureId;
        $resource->characterId = $view->characterId;
        $resource->characterName = $characterName;
        $resource->periodStart = $view->periodStart;
        $resource->periodEnd = $view->periodEnd;
        $resource->reference = $view->reference;
        $resource->tax = $view->tax;
        $resource->paid = $view->paid;
        $resource->remaining = $view->remaining;
        $resource->status = $view->status->value;
        $resource->outsideAlliance = $view->outsideAlliance;
        $resource->unknownTypeIds = $view->unknownTypeIds;

        return $resource;
    }

    public function mineToResource(MoonTaxStatementView $view): MoonTaxMineResource
    {
        $resource = new MoonTaxMineResource();
        $resource->corporationId = $view->corporationId;
        $resource->structureId = $view->structureId;
        $resource->characterId = $view->characterId;
        $resource->periodStart = $view->periodStart;
        $resource->periodEnd = $view->periodEnd;
        $resource->reference = $view->reference;
        $resource->tax = $view->tax;
        $resource->paid = $view->paid;
        $resource->remaining = $view->remaining;
        $resource->status = $view->status->value;
        $resource->outsideAlliance = $view->outsideAlliance;
        $resource->unknownTypeIds = $view->unknownTypeIds;

        return $resource;
    }
}
