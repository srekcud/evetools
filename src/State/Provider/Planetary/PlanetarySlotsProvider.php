<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySlotsResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\Planetary\PlanetarySlotCalculator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetarySlotsResource>
 */
final readonly class PlanetarySlotsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryColonyRepository $colonyRepository,
        private CachedCharacterSkillRepository $skillRepository,
        private PlanetarySlotCalculator $slotCalculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetarySlotsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $coloniesByCharacterId = array_count_values(array_map(
            static fn (PlanetaryColony $colony): int => $colony->getCharacter()->getEveCharacterId(),
            $this->colonyRepository->findByUser($user),
        ));

        $mainEveCharacterId = $user->getMainCharacter()?->getEveCharacterId();
        $inputs = [];
        $namesByCharacterId = [];
        $mainFlagsByCharacterId = [];

        foreach ($user->getCharacters() as $character) {
            $eveCharacterId = $character->getEveCharacterId();
            $levels = $this->skillRepository->findLevelsByEveCharacterId($eveCharacterId);

            $namesByCharacterId[$eveCharacterId] = $character->getName();
            $mainFlagsByCharacterId[$eveCharacterId] = $eveCharacterId === $mainEveCharacterId;
            $inputs[] = [
                'characterId' => $eveCharacterId,
                'coloniesUsed' => array_key_exists($eveCharacterId, $coloniesByCharacterId) ? $coloniesByCharacterId[$eveCharacterId] : 0,
                'consolidationLevel' => $levels[CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION] ?? null,
            ];
        }

        $summary = $this->slotCalculator->calculate($inputs);

        $resource = new PlanetarySlotsResource();
        $resource->characters = array_map(
            static fn (array $row): array => [
                'characterId' => $row['characterId'],
                'characterName' => $namesByCharacterId[$row['characterId']],
                'isMain' => $mainFlagsByCharacterId[$row['characterId']],
                'coloniesUsed' => $row['coloniesUsed'],
                'consolidationLevel' => $row['consolidationLevel'],
                'maxColonies' => $row['maxColonies'],
            ],
            $summary['characters'],
        );
        $resource->totalUsed = $summary['totalUsed'];
        $resource->totalMax = $summary['totalMax'];

        return $resource;
    }
}
