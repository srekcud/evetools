<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\ProjectStepStructureResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectStepStructureResolver::class)]
final class ProjectStepStructureResolverPrecedenceTest extends TestCase
{
    private const int ALICE_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID = 30004759;
    private const int ALICE_FAVORITE_REACTION_SOLAR_SYSTEM_ID = 30001234;
    private const int BOB_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID = 30002187;
    private const int BOB_FAVORITE_REACTION_SOLAR_SYSTEM_ID = 30003410;
    private const int JITA_SOLAR_SYSTEM_ID = 30000142;
    private const int AMARR_SOLAR_SYSTEM_ID = 30002187;
    private const int RETAINED_STRUCTURE_SOLAR_SYSTEM_ID = 30045352;

    private User $alice;
    private User $bob;
    private ProjectStepStructureResolver $resolver;
    private ?IndustryStructureConfig $structureRetainedForTheBonus = null;

    protected function setUp(): void
    {
        $this->alice = new User();
        $this->bob = new User();
        $settingsByUser = new \SplObjectStorage();
        $settingsByUser[$this->alice] = (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId(self::ALICE_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(self::ALICE_FAVORITE_REACTION_SOLAR_SYSTEM_ID);
        $settingsByUser[$this->bob] = (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId(self::BOB_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(self::BOB_FAVORITE_REACTION_SOLAR_SYSTEM_ID);

        $userSettingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $userSettingsRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryUserSettings => isset($criteria['user']) && $settingsByUser->offsetExists($criteria['user'])
                ? $settingsByUser[$criteria['user']]
                : null,
        );
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('getStructureBonusForStep')->willReturnCallback(fn (): array => [
            'structure' => $this->structureRetainedForTheBonus,
            'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
            'timeBonus' => 0.0,
            'name' => null,
            'favoriteSystemWithoutSuitableStructure' => false,
        ]);
        $this->resolver = new ProjectStepStructureResolver($userSettingsRepository, $calculationService);
    }

    public function testManufacturingStepWithoutStructureUsesTheFavoriteSolarSystemOfTheUserOwningTheProject(): void
    {
        $this->assertSame(self::ALICE_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOfUser($this->alice, 'manufacturing')));
        $this->assertSame(self::BOB_FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOfUser($this->bob, 'manufacturing')));
    }

    public function testReactionStepWithoutStructureUsesTheFavoriteReactionSolarSystemOfTheUserOwningTheProject(): void
    {
        $this->assertSame(self::BOB_FAVORITE_REACTION_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOfUser($this->bob, 'reaction')));
        $this->assertSame(self::ALICE_FAVORITE_REACTION_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOfUser($this->alice, 'reaction')));
    }

    public function testStepOwnStructureWinsOverTheStructureRetainedForTheBonus(): void
    {
        $this->structureRetainedForTheBonus = $this->structureIn(self::RETAINED_STRUCTURE_SOLAR_SYSTEM_ID, 1.0);
        $step = $this->stepOfUser($this->alice, 'manufacturing')->setStructureConfig($this->structureIn(self::AMARR_SOLAR_SYSTEM_ID, 3.5));

        $this->assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($step));
        $this->assertSame(3.5, $this->resolver->facilityTaxPercentOf($step));
    }

    public function testStepWithoutStructureOfAUserWithoutSettingsFallsBackToJitaWhileOtherUsersHaveSettings(): void
    {
        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->solarSystemOf($this->stepOfUser(new User(), 'manufacturing')));
    }

    private function structureIn(int $solarSystemId, float $facilityTaxPercent): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setStructureType('raitaru')
            ->setSolarSystemId($solarSystemId)
            ->setFacilityTaxPercent($facilityTaxPercent);
    }

    private function stepOfUser(User $user, string $activityType): IndustryProjectStep
    {
        $project = (new IndustryProject())->setUser($user);
        $step = (new IndustryProjectStep())->setActivityType($activityType)->setDepth(0);
        $project->addStep($step);

        return $step;
    }
}
