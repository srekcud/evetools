<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\InventionService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
final class IndustryResourceMapperOutsideFavoriteSystemTest extends TestCase
{
    use AutowiresByType;
    use ResolvesTypeNamesFromSde;

    private const float DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK = 500000.0;
    private const float MINIMUM_OUTSIDE_SAVING_IN_ISK = 125000.0;

    public function testProjectResourceExposesTheOutsideFavoriteSystemOptionOfTheProject(): void
    {
        $project = $this->project()
            ->setAcceptsOutsideFavoriteSystem(true)
            ->setMinimumOutsideSaving(self::MINIMUM_OUTSIDE_SAVING_IN_ISK);

        $resource = $this->mapper()->projectToResource($project);

        $this->assertTrue($resource->acceptsOutsideFavoriteSystem);
        $this->assertSame(self::MINIMUM_OUTSIDE_SAVING_IN_ISK, $resource->minimumOutsideSaving);
    }

    public function testProjectResourceOfAProjectWithoutTheOptionExposesTheDefaults(): void
    {
        $resource = $this->mapper()->projectToResource($this->project());

        $this->assertFalse($resource->acceptsOutsideFavoriteSystem);
        $this->assertSame(self::DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK, $resource->minimumOutsideSaving);
    }

    private function project(): IndustryProject
    {
        return (new IndustryProject())
            ->setOwner(new User())
            ->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID)
            ->setRuns(1);
    }

    private function mapper(): IndustryResourceMapper
    {
        return self::autowired(IndustryResourceMapper::class, [
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            $this->createStub(CachedCharacterSkillRepository::class),
            $this->createStub(IndustryActivityRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CachedIndustryJobRepository::class),
            $this->createStub(InventionService::class),
            $this->createStub(IndustryActivityMaterialRepository::class),
        ]);
    }
}
