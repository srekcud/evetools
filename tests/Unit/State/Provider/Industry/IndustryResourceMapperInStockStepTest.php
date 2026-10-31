<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\Industry\InventionService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\Tests\Unit\Service\Industry\AutowiresByType;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryResourceMapper::class)]
#[Group('issue-182')]
final class IndustryResourceMapperInStockStepTest extends TestCase
{
    use AutowiresByType;
    use ResolvesTypeNamesFromSde;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;
    private const float FERNITE_ALLOY_UNIT_PRICE = 1_450.0;
    private const float HYDROGEN_FUEL_BLOCK_UNIT_PRICE = 18_500.0;

    public function testFerniteAlloyStepCoveredByAPurchaseOfItsProductIsInStock(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteAlloy->addPurchase($this->purchaseOf(self::FERNITE_ALLOY, 400, self::FERNITE_ALLOY_UNIT_PRICE));

        self::assertSame([[true, 400]], $this->inStockAndPurchasedQuantityOf([$ferniteAlloy]));
    }

    public function testFerniteAlloyStepCoveredByItsStockIsInStock(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteAlloy->setInStockQuantity(400);

        self::assertSame([[true, 0]], $this->inStockAndPurchasedQuantityOf([$ferniteAlloy]));
    }

    public function testFerniteAlloyStepWithAPurchaseOfAMaterialIsNotInStock(): void
    {
        [, $ferniteAlloy] = $this->ferniteCarbideProjectOfFourRuns();
        $ferniteAlloy->addPurchase($this->purchaseOf(self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 400, self::HYDROGEN_FUEL_BLOCK_UNIT_PRICE));

        self::assertSame([[false, 0]], $this->inStockAndPurchasedQuantityOf([$ferniteAlloy]));
    }

    public function testStepsWithoutStockNorPurchaseAreNotInStock(): void
    {
        $steps = $this->ferniteCarbideProjectOfFourRuns();

        self::assertSame([[false, 0], [false, 0], [false, 0]], $this->inStockAndPurchasedQuantityOf($steps));
    }

    private function inStockAndPurchasedQuantityOf(array $steps): array
    {
        $mapper = $this->mapper();

        return array_map(static function (IndustryProjectStep $step) use ($mapper): array {
            $resource = $mapper->stepToResource($step);

            return [$resource->inStock, $resource->purchasedQuantity];
        }, $steps);
    }

    private function ferniteCarbideProjectOfFourRuns(): array
    {
        $project = (new IndustryProject())->setUser(new User())->setProductTypeId(self::FERNITE_CARBIDE)->setRuns(4);

        return [
            $this->stepOf($project, self::FERNITE_CARBIDE, 'reaction', runs: 4, quantity: 40_000, depth: 0),
            $this->stepOf($project, self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1),
            $this->stepOf($project, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 'manufacturing', runs: 1, quantity: 30, depth: 2),
        ];
    }

    private function stepOf(IndustryProject $project, int $productTypeId, string $activityType, int $runs, int $quantity, int $depth): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setSortOrder($depth)
            ->setStructureConfig((new IndustryStructureConfig())->setName('Tatara Delve')->setStructureType('tatara')->setSecurityType('nullsec'));
        $project->addStep($step);

        return $step;
    }

    private function purchaseOf(int $typeId, int $quantity, float $unitPrice): IndustryStepPurchase
    {
        return (new IndustryStepPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
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
        ]);
    }
}
