<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\CharacterSkillCollectionResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedCharacterSkillRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, CharacterSkillCollectionResource>
 */
class SyncCharacterSkillsProcessor implements ProcessorInterface
{
    /** @var int[]|null */
    private ?array $relevantSkillIds = null;

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly CachedCharacterSkillRepository $skillRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CharacterSkillCollectionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $result = new CharacterSkillCollectionResource();
        $syncedCount = 0;
        $warning = null;

        foreach ($user->getCharacters() as $character) {
            try {
                $this->syncCharacterSkills($character);
                $syncedCount++;
            } catch (EsiApiException $e) {
                if (in_array($e->statusCode, [502, 503, 504], true)) {
                    $warning = 'ESI est actuellement en maintenance. Les skills seront synchronisés ultérieurement.';
                } else {
                    $this->logger->warning('Failed to sync skills for character', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                        'statusCode' => $e->statusCode,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync skills for character', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }

            $skills = $this->skillRepository->findBy(['character' => $character]);
            $result->characters[] = $this->mapper->characterSkillsToResource($character, $skills);
        }

        $result->syncedCount = $syncedCount;
        $result->warning = $warning;

        return $result;
    }

    private function syncCharacterSkills(Character $character): void
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return;
        }

        if (!$token->hasScope('esi-skills.read_skills.v1')) {
            return;
        }

        if ($token->isExpiringSoon()) {
            $this->tokenManager->refreshAccessToken($token);
        }

        $characterId = $character->getEveCharacterId();
        $skillsData = $this->esiClient->get(
            "/characters/{$characterId}/skills/",
            $token,
        );

        $allEsiSkills = $skillsData['skills'] ?? [];
        $now = new \DateTimeImmutable();

        // Only sync manufacturing-relevant skills (base industry + blueprint science skills)
        $relevantSkillIds = $this->getManufacturingRelevantSkillIds();

        // Index ESI skills by ID for fast lookup
        $esiBySkillId = [];
        foreach ($allEsiSkills as $esiSkill) {
            $skillId = $esiSkill['skill_id'] ?? 0;
            if ($skillId > 0) {
                $esiBySkillId[$skillId] = $esiSkill;
            }
        }

        // Load all existing skills for this character in one query
        $existingSkills = $this->skillRepository->findBy(['character' => $character]);
        $existingBySkillId = [];
        foreach ($existingSkills as $skill) {
            $existingBySkillId[$skill->getSkillId()] = $skill;
        }

        // Upsert only relevant skills (skip manual overrides)
        foreach ($relevantSkillIds as $skillId) {
            $level = 0;
            if (isset($esiBySkillId[$skillId])) {
                $level = $esiBySkillId[$skillId]['active_skill_level']
                    ?? $esiBySkillId[$skillId]['trained_skill_level']
                    ?? 0;
            }

            if (isset($existingBySkillId[$skillId])) {
                $existing = $existingBySkillId[$skillId];
                if (!$existing->isManual()) {
                    $existing->setLevel($level);
                    $existing->setCachedAt($now);
                }
            } elseif ($level > 0) {
                $skill = new CachedCharacterSkill();
                $skill->setCharacter($character);
                $skill->setSkillId($skillId);
                $skill->setLevel($level);
                $skill->setIsManual(false);
                $skill->setCachedAt($now);
                $this->entityManager->persist($skill);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Get all skill IDs relevant to manufacturing/reactions from the SDE.
     * Includes base industry skills + all science skills required by blueprints.
     *
     * @return int[]
     */
    private function getManufacturingRelevantSkillIds(): array
    {
        if ($this->relevantSkillIds !== null) {
            return $this->relevantSkillIds;
        }

        $conn = $this->entityManager->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT DISTINCT skill_id FROM sde_industry_activity_skills WHERE activity_id IN (1, 11)'
        );

        $skillIds = array_map(fn ($r) => (int) $r['skill_id'], $rows);

        // Ensure the 3 base industry skills are always included
        foreach (CachedCharacterSkill::INDUSTRY_SKILL_IDS as $id) {
            if (!in_array($id, $skillIds, true)) {
                $skillIds[] = $id;
            }
        }

        $this->relevantSkillIds = $skillIds;

        return $skillIds;
    }
}
