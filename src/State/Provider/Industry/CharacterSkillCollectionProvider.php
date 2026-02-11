<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\CharacterSkillCollectionResource;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CharacterSkillCollectionResource>
 */
class CharacterSkillCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly IndustryResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterSkillCollectionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $result = new CharacterSkillCollectionResource();

        foreach ($user->getCharacters() as $character) {
            $skills = $this->skillRepository->findBy(['character' => $character]);
            $result->characters[] = $this->mapper->characterSkillsToResource($character, $skills);
        }

        return $result;
    }
}
