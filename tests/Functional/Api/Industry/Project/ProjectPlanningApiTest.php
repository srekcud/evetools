<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-338')]
final class ProjectPlanningApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int HULL_BLUEPRINT_TYPE_ID = 990_338_001;
    private const int HULL_TYPE_ID = 990_338_002;
    private const int HULL_BASE_TIME_SECONDS = 6_000;
    private const int HULL_RUNS = 3;
    private const int HULL_TE_LEVEL = 10;
    private const int HULL_DURATION_SECONDS = 11_016;
    private const int BLUEPRINT_CATEGORY_ID = 990_747_001;
    private const int BLUEPRINT_GROUP_ID = 990_747_002;

    private const array PLANNING_KEYS = [
        'anchor',
        'characters',
        'delayCause',
        'delaySeconds',
        'expectedEnd',
        'id',
        'lanesShown',
        'plannedEnd',
        'structureTimeBonusIncluded',
        'unscheduled',
    ];

    private const array CHARACTER_KEYS = ['characterName', 'endsAt', 'jobs', 'skills', 'skillsUnknown', 'slots'];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testOwnerOfAProjectWithoutOtherParticipantReadsThePlanningWithOneLanePerCharacterAndNullEnds(): void
    {
        $this->cacheSkills($this->owner->getMainCharacter(), [
            CachedCharacterSkill::SKILL_INDUSTRY => 4,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 3,
            CachedCharacterSkill::SKILL_REACTIONS => 2,
            CachedCharacterSkill::SKILL_MASS_PRODUCTION => 2,
            CachedCharacterSkill::SKILL_ADVANCED_MASS_PRODUCTION => 1,
            CachedCharacterSkill::SKILL_MASS_REACTIONS => 1,
        ]);
        $this->persistCharacterOf($this->owner, 'Brin Solberg');
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        $planning = $this->responseJson();
        $characters = $this->rowsSortedBy('characterName', $planning['characters']);
        $keys = $planning;
        ksort($keys);
        self::assertSame(
            [
                self::PLANNING_KEYS,
                $this->idOfProject($this->hecateBatch),
                $this->hecateBatch->getCreatedAt()->getTimestamp(),
                true,
                true,
                [null, null, null, null],
                [],
                [self::CHARACTER_KEYS, self::CHARACTER_KEYS],
                [
                    ['Aria Solberg', false, ['manufacturing' => 4, 'reaction' => 2], ['industry' => 4, 'advancedIndustry' => 3, 'reactions' => 2], null, []],
                    ['Brin Solberg', true, null, null, null, []],
                ],
            ],
            [
                array_keys($keys),
                $planning['id'],
                (new \DateTimeImmutable($planning['anchor']))->getTimestamp(),
                $planning['structureTimeBonusIncluded'],
                $planning['lanesShown'],
                [$planning['plannedEnd'], $planning['expectedEnd'], $planning['delaySeconds'], $planning['delayCause']],
                $planning['unscheduled'],
                array_map(static function (array $character): array {
                    $keys = $character;
                    ksort($keys);

                    return array_keys($keys);
                }, $characters),
                array_map(
                    static fn (array $character): array => [
                        $character['characterName'],
                        $character['skillsUnknown'],
                        $character['slots'],
                        $character['skills'],
                        $character['endsAt'],
                        $character['jobs'],
                    ],
                    $characters,
                ),
            ],
        );
    }

    #[Group('issue-339')]
    public function testMemberOfAGroupProjectReadsTheEndsAndTheDelayWithoutAnyLane(): void
    {
        self::assertSame([false, [], [null, null, null]], $this->planningOfAGroupProjectSeenBy($this->member));
    }

    #[Group('issue-339')]
    public function testOwnerOfAGroupProjectReadsTheEndsAndTheDelayWithoutAnyLane(): void
    {
        self::assertSame([false, [], [null, null, null]], $this->planningOfAGroupProjectSeenBy($this->owner));
    }

    private function planningOfAGroupProjectSeenBy(User $reader): array
    {
        $this->persistCharacterOf($this->owner, 'Brin Solberg');
        $this->addMembership($this->hecateBatch, $this->member, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->client->loginUser($reader, 'api');
        $this->request('GET', $this->planningUri($this->hecateBatch));
        self::assertResponseStatusCodeSame(200);
        $planning = $this->responseJson();

        return [$planning['lanesShown'], $planning['characters'], [$planning['plannedEnd'], $planning['expectedEnd'], $planning['delaySeconds']]];
    }

    #[Group('issue-339')]
    public function testPendingParticipantDoesNotTakeTheLanesAwayFromTheOwnerOfAProject(): void
    {
        $this->addMembership($this->hecateBatch, $this->pendingMember, ParticipantRole::Member, ParticipantStatus::Pending);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($this->hecateBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(true, $this->responseJson()['lanesShown']);
    }

    public function testPlannedEndIsTheCreationOfTheProjectPlusTheDurationOfItsManufacturingJobWithTheSkillsOfTheCharacter(): void
    {
        $this->insertHullBlueprint();
        $this->cacheSkills($this->owner->getMainCharacter(), [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]);
        $hullBatch = $this->persistProject('Hull batch');
        $this->addMembership($hullBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addJobLine($hullBatch, self::HULL_TYPE_ID, 'Synthetic Hull', 'final', self::HULL_RUNS, self::HULL_RUNS, 10, self::HULL_TE_LEVEL, null);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($hullBatch));

        self::assertResponseStatusCodeSame(200);
        $planning = $this->responseJson();
        $anchor = $hullBatch->getCreatedAt()->getTimestamp();
        self::assertSame(
            [
                $anchor,
                $anchor + self::HULL_DURATION_SECONDS,
                [],
            ],
            [
                (new \DateTimeImmutable($planning['anchor']))->getTimestamp(),
                null === $planning['plannedEnd'] ? null : (new \DateTimeImmutable($planning['plannedEnd']))->getTimestamp(),
                $planning['unscheduled'],
            ],
        );
    }

    public function testPlannedStructureNoLongerConfiguredKeepsTheBaseDurationAndFlagsTheMissingStructureTimeBonus(): void
    {
        $this->insertHullBlueprint();
        $this->cacheSkills($this->owner->getMainCharacter(), [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]);
        $hullBatch = $this->persistProject('Hull batch');
        $this->addMembership($hullBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addJobLine($hullBatch, self::HULL_TYPE_ID, 'Synthetic Hull', 'final', self::HULL_RUNS, self::HULL_RUNS, 10, self::HULL_TE_LEVEL, null)
            ->setPlannedStructureId(Uuid::v7()->toRfc4122());
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($hullBatch));

        self::assertResponseStatusCodeSame(200);
        $planning = $this->responseJson();
        self::assertSame(
            [false, $hullBatch->getCreatedAt()->getTimestamp() + self::HULL_DURATION_SECONDS],
            [$planning['structureTimeBonusIncluded'], (new \DateTimeImmutable($planning['plannedEnd']))->getTimestamp()],
        );
    }

    public function testPlannedEndWithoutPlannedStructureFlagsTheStructureTimeBonusAsIncluded(): void
    {
        $this->insertHullBlueprint();
        $this->cacheSkills($this->owner->getMainCharacter(), [CachedCharacterSkill::SKILL_INDUSTRY => 5]);
        $hullBatch = $this->persistProject('Hull batch');
        $this->addMembership($hullBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addJobLine($hullBatch, self::HULL_TYPE_ID, 'Synthetic Hull', 'final', self::HULL_RUNS, self::HULL_RUNS, 10, self::HULL_TE_LEVEL, null);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($hullBatch));

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseJson()['structureTimeBonusIncluded']);
    }

    #[Group('issue-747')]
    public function testJobOfALaneCarriesTheNameOfTheBlueprintThatBuildsItsProduct(): void
    {
        self::assertSame('Synthetic Hull Blueprint', $this->blueprintNameOfTheHullJob(true));
    }

    #[Group('issue-747')]
    public function testJobWhoseBlueprintHasNoSdeTypeCarriesNoBlueprintNameInsteadOfAGuessedOne(): void
    {
        self::assertNull($this->blueprintNameOfTheHullJob(false));
    }

    private function blueprintNameOfTheHullJob(bool $withBlueprintType): ?string
    {
        $this->insertHullBlueprint();
        if ($withBlueprintType) {
            $category = (new InvCategory())->setCategoryId(self::BLUEPRINT_CATEGORY_ID)->setCategoryName('Test Blueprint');
            $group = (new InvGroup())->setGroupId(self::BLUEPRINT_GROUP_ID)->setGroupName('Test Blueprint Group')->setCategory($category);
            $this->em->persist($category);
            $this->em->persist($group);
            $this->em->persist((new InvType())->setTypeId(self::HULL_BLUEPRINT_TYPE_ID)->setTypeName('Synthetic Hull Blueprint')->setGroup($group)->setPublished(true));
        }
        $this->cacheSkills($this->owner->getMainCharacter(), [
            CachedCharacterSkill::SKILL_INDUSTRY => 5,
            CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 5,
        ]);
        $hullBatch = $this->persistProject('Hull batch');
        $this->addMembership($hullBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addJobLine($hullBatch, self::HULL_TYPE_ID, 'Synthetic Hull', 'final', self::HULL_RUNS, self::HULL_RUNS, 10, self::HULL_TE_LEVEL, null);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->planningUri($hullBatch));

        self::assertResponseStatusCodeSame(200);
        $jobs = array_merge(...array_column($this->responseJson()['characters'], 'jobs'));
        self::assertCount(1, $jobs);
        self::assertArrayHasKey('blueprintName', $jobs[0]);

        return $jobs[0]['blueprintName'];
    }

    public function testPilotOfAnotherCorporationIsRefusedThePlanning(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->planningUri($this->ishtarBatch));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testPlanningOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', '/api/industry/projects/'.Uuid::v7()->toRfc4122().'/planning');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function planningUri(IndustryProject $project): string
    {
        return '/api/industry/projects/'.$this->idOfProject($project).'/planning';
    }

    private function idOfProject(IndustryProject $project): string
    {
        return (string) $project->getId()?->toRfc4122();
    }

    private function persistCharacterOf(User $user, string $name): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setCorporationName(sprintf('Corporation %d', self::OWNER_CORPORATION_ID));
        $user->addCharacter($character);
        $this->em->persist($character);

        return $character;
    }

    private function cacheSkills(Character $character, array $levelsBySkillId): void
    {
        foreach ($levelsBySkillId as $skillId => $level) {
            $this->em->persist((new CachedCharacterSkill())->setCharacter($character)->setSkillId($skillId)->setLevel($level));
        }
    }

    private function insertHullBlueprint(): void
    {
        $connection = $this->em->getConnection();
        $connection->insert('sde_industry_blueprints', [
            'type_id' => self::HULL_BLUEPRINT_TYPE_ID,
            'max_production_limit' => 300,
        ]);
        $connection->insert('sde_industry_activities', [
            'type_id' => self::HULL_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'time' => self::HULL_BASE_TIME_SECONDS,
        ]);
        $connection->insert('sde_industry_activity_products', [
            'type_id' => self::HULL_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::HULL_TYPE_ID,
            'quantity' => 1,
        ]);
    }
}
