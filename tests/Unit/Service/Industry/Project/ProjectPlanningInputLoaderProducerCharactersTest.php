<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Industry\Application\PlannedJobTimeModifier;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Service\Industry\Project\PlanningCharacter;
use App\Service\Industry\Project\ProjectPlanningInputLoader;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectPlanningInputLoader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1000')]
final class ProjectPlanningInputLoaderProducerCharactersTest extends TestCase
{
    private const string OWNER_ID = '01890a5d-ac96-774b-bcce-b302099a8057';
    private const int ARIA_EVE_CHARACTER_ID = 2_112_000_001;
    private const int BRIN_EVE_CHARACTER_ID = 2_112_000_002;
    private const int UNLINKED_EVE_CHARACTER_ID = 2_112_999_999;

    public function testProjectWithoutSelectionPlansWithEveryCharacterOfItsOwner(): void
    {
        self::assertSame(['Aria Solberg', 'Brin Solberg'], $this->plannedCharacterNamesOf(null));
    }

    public function testProjectWithASelectionPlansWithTheSelectedCharactersOnly(): void
    {
        self::assertSame(['Brin Solberg'], $this->plannedCharacterNamesOf([self::BRIN_EVE_CHARACTER_ID]));
    }

    public function testSelectedCharacterNoLongerLinkedToTheOwnerIsIgnoredWithoutFallingBackToEveryone(): void
    {
        self::assertSame(['Aria Solberg'], $this->plannedCharacterNamesOf([self::ARIA_EVE_CHARACTER_ID, self::UNLINKED_EVE_CHARACTER_ID]));
        self::assertSame([], $this->plannedCharacterNamesOf([self::UNLINKED_EVE_CHARACTER_ID]));
    }

    /**
     * @param list<int>|null $producerCharacterIds
     *
     * @return list<string>
     */
    private function plannedCharacterNamesOf(?array $producerCharacterIds): array
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(Uuid::fromString(self::OWNER_ID));
        $project = (new IndustryProject())->setOwner($owner)->setProducerCharacterIds($producerCharacterIds);
        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findBy')->willReturn([]);
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findByUser')->willReturn([
            (new Character())->setEveCharacterId(self::ARIA_EVE_CHARACTER_ID)->setName('Aria Solberg'),
            (new Character())->setEveCharacterId(self::BRIN_EVE_CHARACTER_ID)->setName('Brin Solberg'),
        ]);
        $loader = new ProjectPlanningInputLoader(
            $bomLineRepository,
            $this->createStub(CachedIndustryJobRepository::class),
            $characterRepository,
            $this->createStub(CachedCharacterSkillRepository::class),
            InMemoryBlueprintCatalog::fromSdeFixture(),
            $this->createStub(IndustryActivityProductRepository::class),
            $this->createStub(IndustryActivitySkillRepository::class),
            new InMemoryUserProductionStructures([self::OWNER_ID => []]),
            new PlannedJobTimeModifier(new InMemoryRigCategories()),
        );

        return array_map(static fn (PlanningCharacter $character): string => $character->name, $loader->load($project)->characters);
    }
}
