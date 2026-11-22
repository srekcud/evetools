<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\CharacterLocationResource;
use App\Entity\Character;
use App\Entity\User;
use App\Service\ESI\CharacterLocationReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CharacterLocationResource>
 */
class CharacterLocationCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterLocationReader $locationReader,
    ) {
    }

    /**
     * @return CharacterLocationResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return array_map(
            fn (Character $character): CharacterLocationResource => $this->toResource($character),
            $user->getCharacters()->toArray(),
        );
    }

    private function toResource(Character $character): CharacterLocationResource
    {
        $location = $this->locationReader->read($character);

        $resource = new CharacterLocationResource();
        $resource->id = $character->getId()?->toRfc4122() ?? '';
        $resource->eveCharacterId = $character->getEveCharacterId();
        $resource->solarSystemId = $location?->solarSystemId;
        $resource->stationId = $location?->stationId;
        $resource->structureId = $location?->structureId;
        $resource->online = $location?->online;
        $resource->lastLoginAt = $location?->lastLoginAt?->format('c');

        return $resource;
    }
}
