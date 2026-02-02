<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\CharacterResource;

/**
 * @implements ProviderInterface<CharacterResource>
 */
class CharacterDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterResource
    {
        $resource = new CharacterResource();
        $resource->id = (string) $uriVariables['id'];

        return $resource;
    }
}
