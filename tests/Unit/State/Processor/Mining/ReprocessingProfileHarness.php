<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use App\ApiResource\Input\Mining\ReprocessingProfileInput;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\ReprocessingProfile;
use App\Entity\User;
use App\Enum\ReprocessingFailure;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Repository\ReprocessingProfileRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Mining\RefreshReprocessingProfileProcessor;
use App\State\Processor\Mining\SaveReprocessingProfileProcessor;
use App\State\Provider\Mining\ReprocessingProfileProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

trait ReprocessingProfileHarness
{
    private const string SKILLS_SCOPE = 'esi-skills.read_skills.v1';
    private const string MINING_SCOPE = 'esi-industry.read_character_mining.v1';
    private const string NOW = '2026-11-03 21:00:00';
    private const string YESTERDAY = '2026-11-02 18:30:00';
    private const float YIELD_DELTA = 1e-9;

    private const int PILOT_ONE_ID = 2_112_269_001;
    private const int PILOT_TWO_ID = 2_112_269_002;
    private const int OTHER_USER_PILOT_ID = 2_112_269_003;
    private const int UNKNOWN_PILOT_ID = 2_112_269_999;
    private const string PILOT_ONE_SKILLS_ENDPOINT = '/characters/2112269001/skills/';
    private const string PILOT_TWO_SKILLS_ENDPOINT = '/characters/2112269002/skills/';
    private const string OTHER_USER_PILOT_SKILLS_ENDPOINT = '/characters/2112269003/skills/';

    private const int REPROCESSING = 3385;
    private const int REPROCESSING_EFFICIENCY = 3389;
    private const int SIMPLE_ORE_PROCESSING = 60377;
    private const int COHERENT_ORE_PROCESSING = 60378;
    private const int ICE_PROCESSING = 18025;
    private const int MINING = 3386;

    private const array ORE_PROCESSING_SKILLS = [
        ['typeId' => self::COHERENT_ORE_PROCESSING, 'typeName' => 'Coherent Ore Processing'],
        ['typeId' => self::ICE_PROCESSING, 'typeName' => 'Ice Processing'],
        ['typeId' => self::SIMPLE_ORE_PROCESSING, 'typeName' => 'Simple Ore Processing'],
    ];

    private User $userA;
    private User $userB;
    private ?User $currentUser = null;
    private Character $pilotOne;
    private Character $pilotTwo;
    private Character $otherUserPilot;
    private EveToken $pilotOneToken;
    private EveToken $pilotTwoToken;
    private MockClock $clock;

    private array $esiResponsesByEndpoint = [];

    private array $esiFailuresByEndpoint = [];

    private array $esiCalls = [];

    private array $storedProfiles = [];

    private int $flushCount = 0;

    private function setUpReprocessingProfileHarness(): void
    {
        $this->userA = new User();
        $this->userB = new User();
        $this->currentUser = $this->userA;
        $this->pilotOneToken = (new EveToken())->setScopes([self::MINING_SCOPE, self::SKILLS_SCOPE]);
        $this->pilotTwoToken = (new EveToken())->setScopes([self::SKILLS_SCOPE]);
        $this->pilotOne = $this->addCharacter($this->userA, 'Ilyra Castell', self::PILOT_ONE_ID, $this->pilotOneToken);
        $this->pilotTwo = $this->addCharacter($this->userA, 'Tovan Reske', self::PILOT_TWO_ID, $this->pilotTwoToken);
        $this->otherUserPilot = $this->addCharacter($this->userB, 'Maren Duskholm', self::OTHER_USER_PILOT_ID, (new EveToken())->setScopes([self::SKILLS_SCOPE]));
        $this->clock = new MockClock(self::NOW, 'UTC');

        $this->esiResponsesByEndpoint[self::PILOT_ONE_SKILLS_ENDPOINT] = ['skills' => [
            $this->esiSkill(self::REPROCESSING, 5),
            $this->esiSkill(self::REPROCESSING_EFFICIENCY, 5),
            $this->esiSkill(self::SIMPLE_ORE_PROCESSING, 5),
            $this->esiSkill(self::MINING, 4),
        ]];
        $this->esiResponsesByEndpoint[self::PILOT_TWO_SKILLS_ENDPOINT] = ['skills' => [
            $this->esiSkill(self::REPROCESSING, 4),
            $this->esiSkill(self::REPROCESSING_EFFICIENCY, 3),
            $this->esiSkill(self::SIMPLE_ORE_PROCESSING, 5),
            $this->esiSkill(self::COHERENT_ORE_PROCESSING, 2),
        ]];
        $this->esiResponsesByEndpoint[self::OTHER_USER_PILOT_SKILLS_ENDPOINT] = ['skills' => [
            $this->esiSkill(self::REPROCESSING, 2),
            $this->esiSkill(self::REPROCESSING_EFFICIENCY, 1),
            $this->esiSkill(self::SIMPLE_ORE_PROCESSING, 3),
        ]];
    }

    private function addCharacter(User $user, string $name, int $eveCharacterId, EveToken $token): Character
    {
        $character = (new Character())->setName($name)->setEveCharacterId($eveCharacterId);
        $character->setEveToken($token);
        $user->addCharacter($character);

        return $character;
    }

    private function esiSkill(int $skillId, int $activeLevel): array
    {
        return ['skill_id' => $skillId, 'active_skill_level' => $activeLevel, 'trained_skill_level' => $activeLevel, 'skillpoints_in_skill' => 1000 * ($activeLevel + 1)];
    }

    private function input(
        ?int $characterId,
        ?int $oreProcessingSkillTypeId,
        ?string $location,
        ?string $rigTier,
        ?string $security,
        ?int $implantPercent,
    ): ReprocessingProfileInput {
        $input = new ReprocessingProfileInput();
        $input->characterId = $characterId;
        $input->oreProcessingSkillTypeId = $oreProcessingSkillTypeId;
        $input->location = $location;
        $input->rigTier = $rigTier;
        $input->security = $security;
        $input->implantPercent = $implantPercent;

        return $input;
    }

    private function storeProfile(
        User $user,
        Character $character,
        int $oreProcessingSkillTypeId,
        ReprocessingLocation $location,
        ?ReprocessingRigTier $rigTier,
        ?ReprocessingSecurity $security,
        int $implantPercent,
        ?array $levels,
        ?float $reprocessYield,
        ?ReprocessingFailure $failure,
    ): ReprocessingProfile {
        $profile = (new ReprocessingProfile())
            ->setUser($user)
            ->setCharacter($character)
            ->setOreProcessingSkillTypeId($oreProcessingSkillTypeId)
            ->setLocation($location)
            ->setRigTier($rigTier)
            ->setSecurity($security)
            ->setImplantPercent($implantPercent)
            ->setReprocessingLevel($levels[0] ?? null)
            ->setReprocessingEfficiencyLevel($levels[1] ?? null)
            ->setOreProcessingLevel($levels[2] ?? null)
            ->setReprocessYield($reprocessYield)
            ->setFailure($failure)
            ->setComputedAt(new \DateTimeImmutable(self::YESTERDAY, new \DateTimeZone('UTC')));
        $this->storedProfiles[] = $profile;

        return $profile;
    }

    private function storedProfileOf(User $user): ?ReprocessingProfile
    {
        foreach ($this->storedProfiles as $profile) {
            if ($profile->getUser() === $user) {
                return $profile;
            }
        }

        return null;
    }

    private function reprocessingProfileProvider(): ReprocessingProfileProvider
    {
        return self::autowiredForReprocessing(ReprocessingProfileProvider::class, $this->boundaries());
    }

    private function saveProcessor(): SaveReprocessingProfileProcessor
    {
        return self::autowiredForReprocessing(SaveReprocessingProfileProcessor::class, $this->boundaries());
    }

    private function refreshProcessor(): RefreshReprocessingProfileProcessor
    {
        return self::autowiredForReprocessing(RefreshReprocessingProfileProcessor::class, $this->boundaries());
    }

    private function boundaries(): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): ?User => $this->currentUser);

        $profileRepository = $this->createStub(ReprocessingProfileRepository::class);
        $profileRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ReprocessingProfile => $this->storedProfileOf($criteria['user']),
        );

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findOreProcessingSkills')->willReturn(self::ORE_PROCESSING_SKILLS);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ReprocessingProfile && !in_array($entity, $this->storedProfiles, true)) {
                $this->storedProfiles[] = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->flushCount++;
        });

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturnCallback(function (string $endpoint, ?EveToken $token = null): array {
            $this->esiCalls[] = [$endpoint, $token];
            if (isset($this->esiFailuresByEndpoint[$endpoint])) {
                throw $this->esiFailuresByEndpoint[$endpoint];
            }

            return $this->esiResponsesByEndpoint[$endpoint];
        });

        return [$security, $profileRepository, $invTypeRepository, $entityManager, $tokenManager, $esiClient, $this->clock];
    }

    private static function autowiredForReprocessing(string $class, array $services): object
    {
        $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        return new $class(...array_map(
            static function (\ReflectionParameter $parameter) use ($class, $services): object {
                $type = (string) $parameter->getType();
                foreach ($services as $service) {
                    if ($service instanceof $type) {
                        return $service;
                    }
                }
                $buildable = str_starts_with($type, 'App\\Service\\Mining\\') || str_starts_with($type, 'App\\State\\Provider\\Mining\\');
                if ($buildable && class_exists($type)) {
                    return self::autowiredForReprocessing($type, $services);
                }

                throw new \LogicException(\sprintf('The harness has no %s for $%s of %s', $type, $parameter->getName(), $class));
            },
            $parameters,
        ));
    }
}
