<?php

declare(strict_types=1);

namespace App\State\Provider\Escalation;

use App\ApiResource\Escalation\EscalationResource;
use App\Entity\Escalation;

class EscalationResourceMapper
{
    public static function toResource(Escalation $escalation, bool $isOwner): EscalationResource
    {
        $resource = new EscalationResource();
        $resource->id = $escalation->getId()?->toRfc4122() ?? '';
        $resource->characterId = $escalation->getCharacterId();
        $resource->characterName = $escalation->getCharacterName();
        $resource->type = $escalation->getType();
        $resource->solarSystemId = $escalation->getSolarSystemId();
        $resource->solarSystemName = $escalation->getSolarSystemName();
        $resource->securityStatus = $escalation->getSecurityStatus();
        $resource->price = $escalation->getPrice();
        $resource->visibility = $escalation->getVisibility()->value;
        $resource->bmStatus = $escalation->getBmStatus()->value;
        $resource->saleStatus = $escalation->getSaleStatus()->value;
        $resource->notes = $escalation->getNotes();
        $resource->corporationId = $escalation->getCorporationId();
        $resource->expiresAt = $escalation->getExpiresAt()->format('c');
        $resource->createdAt = $escalation->getCreatedAt()->format('c');
        $resource->updatedAt = $escalation->getUpdatedAt()->format('c');
        $resource->isOwner = $isOwner;

        return $resource;
    }
}
