<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\CharacterSkillResource;
use App\ApiResource\Input\Industry\UpdateCharacterSkillInput;
use App\Entity\CachedCharacterSkill;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CharacterRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateCharacterSkillInput, CharacterSkillResource>
 */
class UpdateCharacterSkillProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CharacterSkillResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $character = $this->characterRepository->find(Uuid::fromString($uriVariables['characterId']));

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        assert($data instanceof UpdateCharacterSkillInput);

        $skillUpdates = [
            CachedCharacterSkill::SKILL_INDUSTRY => $data->industry,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => $data->advancedIndustry,
            CachedCharacterSkill::SKILL_REACTIONS => $data->reactions,
        ];

        $now = new \DateTimeImmutable();

        foreach ($skillUpdates as $skillId => $level) {
            if ($level === null) {
                continue;
            }

            $existing = $this->skillRepository->findOneBy([
                'character' => $character,
                'skillId' => $skillId,
            ]);

            if ($existing !== null) {
                $existing->setLevel($level);
                $existing->setIsManual(true);
                $existing->setCachedAt($now);
            } else {
                $skill = new CachedCharacterSkill();
                $skill->setCharacter($character);
                $skill->setSkillId($skillId);
                $skill->setLevel($level);
                $skill->setIsManual(true);
                $skill->setCachedAt($now);
                $this->entityManager->persist($skill);
            }
        }

        $this->entityManager->flush();

        $skills = $this->skillRepository->findBy(['character' => $character]);

        return $this->mapper->characterSkillsToResource($character, $skills);
    }
}
