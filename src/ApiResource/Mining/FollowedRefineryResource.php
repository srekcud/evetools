<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Mining\FollowRefineryInput;
use App\Entity\MoonRefineryFollow;
use App\State\Processor\Mining\FollowRefineryProcessor;
use App\State\Processor\Mining\UnfollowRefineryProcessor;
use App\State\Provider\Mining\FollowedRefineryProvider;

#[ApiResource(
    shortName: 'FollowedRefinery',
    operations: [
        new Post(
            uriTemplate: '/mining/followed-refineries',
            processor: FollowRefineryProcessor::class,
            input: FollowRefineryInput::class,
        ),
        new Delete(
            uriTemplate: '/mining/followed-refineries/{structureId}',
            provider: FollowedRefineryProvider::class,
            processor: UnfollowRefineryProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class FollowedRefineryResource
{
    #[ApiProperty(identifier: true)]
    public int $structureId = 0;

    public int $corporationId = 0;

    public string $createdAt = '';

    public static function fromFollow(MoonRefineryFollow $follow): self
    {
        $resource = new self();
        $resource->structureId = $follow->getStructureId();
        $resource->corporationId = $follow->getCorporationId();
        $resource->createdAt = $follow->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
