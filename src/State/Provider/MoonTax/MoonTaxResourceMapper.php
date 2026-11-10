<?php

declare(strict_types=1);

namespace App\State\Provider\MoonTax;

use App\ApiResource\MoonTax\MoonTaxCorporationResource;
use App\ApiResource\MoonTax\MoonTaxMineResource;
use App\ApiResource\MoonTax\MoonTaxStatementResource;
use App\Service\MoonTax\MoonTaxAffiliation;
use App\Service\MoonTax\MoonTaxStatementView;

final readonly class MoonTaxResourceMapper
{
    private const string PROVISIONAL_ID_FORMAT = 'provisional-%d-%d-%s';

    public function statementToResource(MoonTaxStatementView $view, ?string $characterName, ?MoonTaxAffiliation $affiliation = null): MoonTaxStatementResource
    {
        $resource = new MoonTaxStatementResource();
        $resource->id = $view->id ?? sprintf(self::PROVISIONAL_ID_FORMAT, $view->structureId, $view->characterId, $view->periodStart->format('Ymd'));
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
        $resource->recordedCorporationId = $view->recordedCorporationId;
        $resource->affiliationKnown = $affiliation !== null && $affiliation->known;
        $resource->recordedCorporationName = $resource->affiliationKnown ? $affiliation?->corporationName : null;
        $resource->allianceId = $resource->affiliationKnown ? $affiliation?->allianceId : null;
        $resource->allianceName = $resource->affiliationKnown ? $affiliation?->allianceName : null;

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
