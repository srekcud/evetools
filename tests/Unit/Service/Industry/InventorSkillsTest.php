<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Application\InventionSkillLevels;
use App\Industry\Application\InventionSkillRequirement;
use App\Industry\Application\InventionSkillRequirements;
use App\Industry\Domain\Runs;
use App\Repository\CachedCharacterSkillRepository;
use App\Service\Industry\InventorSkills;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventorSkills::class)]
final class InventorSkillsTest extends TestCase
{
    private const int INVENTED_PRODUCT = 22456;
    private const int T1_BLUEPRINT = 20445;
    private const int FIRST_SCIENCE = 11433;
    private const int SECOND_SCIENCE = 11442;
    private const int ENCRYPTION = 23087;

    private bool $productIsInvented = true;
    private InventionSkillRequirement $requirement;

    /** @var array<int, int> skill type id => cached level */
    private array $cachedLevels = [];
    private int $skillReads = 0;

    protected function setUp(): void
    {
        $this->requirement = new InventionSkillRequirement([self::FIRST_SCIENCE, self::SECOND_SCIENCE], self::ENCRYPTION);
    }

    public function testWithoutInventorTheSkillsAreZeroAndNothingIsRead(): void
    {
        $levels = $this->inventorSkills()->levelsFor(self::INVENTED_PRODUCT, null);

        $this->assertEquals(InventionSkillLevels::none(), $levels);
        $this->assertSame(0, $this->skillReads);
    }

    public function testTheLevelsAreThoseCachedForTheInventor(): void
    {
        $this->cachedLevels = [self::FIRST_SCIENCE => 5, self::SECOND_SCIENCE => 3, self::ENCRYPTION => 2];

        $levels = $this->inventorSkills()->levelsFor(self::INVENTED_PRODUCT, new Character());

        $this->assertEquals(new InventionSkillLevels(5, 3, 2), $levels);
    }

    public function testASkillWithoutCachedLineIsUnknownNeverZero(): void
    {
        $this->cachedLevels = [self::FIRST_SCIENCE => 5, self::ENCRYPTION => 0];

        $levels = $this->inventorSkills()->levelsFor(self::INVENTED_PRODUCT, new Character());

        $this->assertEquals(new InventionSkillLevels(5, null, 0), $levels);
        $this->assertFalse($levels->isKnown());
    }

    public function testASlotThatTheRecipeDoesNotUseCountsForZero(): void
    {
        $this->requirement = new InventionSkillRequirement([self::FIRST_SCIENCE, self::SECOND_SCIENCE], null);
        $this->cachedLevels = [self::FIRST_SCIENCE => 4, self::SECOND_SCIENCE => 4];

        $levels = $this->inventorSkills()->levelsFor(self::INVENTED_PRODUCT, new Character());

        $this->assertEquals(new InventionSkillLevels(4, 4, 0), $levels);
        $this->assertTrue($levels->isKnown());
    }

    public function testAProductThatIsNotInventedReadsNoSkill(): void
    {
        $this->productIsInvented = false;

        $levels = $this->inventorSkills()->levelsFor(self::INVENTED_PRODUCT, new Character());

        $this->assertEquals(InventionSkillLevels::none(), $levels);
        $this->assertSame(0, $this->skillReads);
    }

    private function inventorSkills(): InventorSkills
    {
        $catalog = $this->createStub(InventionCatalog::class);
        $catalog->method('inventionOf')->willReturnCallback(
            fn (int $productTypeId): ?InventionRecipe => $this->productIsInvented ? new InventionRecipe(self::T1_BLUEPRINT, 587, 0.34, new Runs(10), []) : null,
        );
        $requirements = $this->createStub(InventionSkillRequirements::class);
        $requirements->method('of')->willReturnCallback(fn (): InventionSkillRequirement => $this->requirement);
        $skills = $this->createStub(CachedCharacterSkillRepository::class);
        $skills->method('findAllSkillsForCharacter')->willReturnCallback(function (): array {
            ++$this->skillReads;

            $indexed = [];
            foreach ($this->cachedLevels as $skillTypeId => $level) {
                $indexed[$skillTypeId] = (new CachedCharacterSkill())->setSkillId($skillTypeId)->setLevel($level);
            }

            return $indexed;
        });

        return new InventorSkills($catalog, $requirements, $skills);
    }
}
