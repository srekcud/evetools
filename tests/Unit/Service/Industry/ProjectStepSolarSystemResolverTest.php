<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\ProjectStepSolarSystemResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectStepSolarSystemResolver::class)]
#[Group('issue-133')]
final class ProjectStepSolarSystemResolverTest extends TestCase
{
    private const int JITA_SOLAR_SYSTEM_ID = 30000142;
    private const int AMARR_SOLAR_SYSTEM_ID = 30002187;
    private const int FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID = 30004759;
    private const int FAVORITE_REACTION_SOLAR_SYSTEM_ID = 30001234;

    private IndustryUserSettingsRepository&Stub $userSettingsRepository;
    private ProjectStepSolarSystemResolver $resolver;

    protected function setUp(): void
    {
        $this->userSettingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $this->resolver = new ProjectStepSolarSystemResolver($this->userSettingsRepository);
    }

    public function testStepWithAStructureInASolarSystemUsesThatSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());
        $step = $this->stepOf('manufacturing', (new IndustryStructureConfig())->setSolarSystemId(self::AMARR_SOLAR_SYSTEM_ID));

        $this->assertSame(self::AMARR_SOLAR_SYSTEM_ID, $this->resolver->forStep($step));
    }

    public function testManufacturingStepWithoutStructureUsesTheFavoriteManufacturingSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('manufacturing')));
    }

    public function testStepWhoseStructureHasNoSolarSystemUsesTheFavoriteManufacturingSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('manufacturing', new IndustryStructureConfig())));
    }

    public function testReactionStepWithoutStructureUsesTheFavoriteReactionSolarSystem(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn($this->settingsWithFavorites());

        $this->assertSame(self::FAVORITE_REACTION_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('reaction')));
    }

    public function testStepWithoutStructureNorFavoriteUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(new IndustryUserSettings());

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('manufacturing')));
    }

    public function testReactionStepWithOnlyAFavoriteManufacturingSolarSystemUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(
            (new IndustryUserSettings())->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID),
        );

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('reaction')));
    }

    public function testStepWithoutStructureOfAUserWithoutSettingsUsesJita(): void
    {
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $this->assertSame(self::JITA_SOLAR_SYSTEM_ID, $this->resolver->forStep($this->stepOf('manufacturing')));
    }

    private function settingsWithFavorites(): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SOLAR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(self::FAVORITE_REACTION_SOLAR_SYSTEM_ID);
    }

    private function stepOf(string $activityType, ?IndustryStructureConfig $structureConfig = null): IndustryProjectStep
    {
        $project = (new IndustryProject())->setUser(new User());
        $step = (new IndustryProjectStep())
            ->setActivityType($activityType)
            ->setDepth(0)
            ->setStructureConfig($structureConfig);
        $project->addStep($step);

        return $step;
    }
}
