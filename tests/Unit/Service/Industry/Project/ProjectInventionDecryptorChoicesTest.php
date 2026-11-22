<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Domain\Runs;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectInventionDecryptorChoices;
use App\Tests\Unit\Industry\Application\Double\InMemoryDecryptors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('issue-1205')]
final class ProjectInventionDecryptorChoicesTest extends TestCase
{
    private const int HAIL_L = 12_779;
    private const int PROTOTYPE_AMMUNITION_L = 990_003;
    private const int RIFTER = 587;
    private const int NOT_A_FINAL_PRODUCT = 24_690;
    private const int NOT_A_DECRYPTOR = 34;

    private IndustryProjectService&MockObject $projectService;
    private User $owner;

    protected function setUp(): void
    {
        $this->projectService = $this->createMock(IndustryProjectService::class);
        $this->owner = new User();
    }

    public function testChoosingADecryptorForAnInventedFinalProductStoresItAndRebuildsTheBomOfADraft(): void
    {
        $project = $this->projectOf();
        $this->projectService->expects(self::once())->method('buildBom')->with($project, $this->owner, false);

        $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT]);

        self::assertSame([self::HAIL_L => InMemoryDecryptors::ATTAINMENT], $project->inventionDecryptorsByProductTypeId());
    }

    #[DataProvider('statusesOfAProjectInProgressProvider')]
    public function testBomOfAProjectThatHasLeftTheDraftRefusesToDropWorkedLines(ProjectStatus $status): void
    {
        $project = $this->projectOf()->setStatus($status);
        $this->projectService->expects(self::once())->method('buildBom')->with($project, $this->owner, true);

        $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::AUGMENTATION]);

        self::assertSame([self::HAIL_L => InMemoryDecryptors::AUGMENTATION], $project->inventionDecryptorsByProductTypeId());
    }

    public static function statusesOfAProjectInProgressProvider(): iterable
    {
        yield 'published' => [ProjectStatus::Published];
        yield 'in progress' => [ProjectStatus::InProgress];
    }

    public function testNullRemovesTheChoiceAndRebuildsTheBom(): void
    {
        $project = $this->projectOf([self::HAIL_L => InMemoryDecryptors::ATTAINMENT, self::PROTOTYPE_AMMUNITION_L => InMemoryDecryptors::AUGMENTATION]);
        $this->projectService->expects(self::once())->method('buildBom');

        $this->choices()->change($project, [self::HAIL_L => null]);

        self::assertSame([self::PROTOTYPE_AMMUNITION_L => InMemoryDecryptors::AUGMENTATION], $project->inventionDecryptorsByProductTypeId());
    }

    public function testChoiceIdenticalToTheStoredOneAndRemovalOfAMissingChoiceChangeNothingAndSkipTheBom(): void
    {
        $project = $this->projectOf([self::HAIL_L => InMemoryDecryptors::ATTAINMENT]);
        $this->projectService->expects(self::never())->method('buildBom');

        $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT, self::PROTOTYPE_AMMUNITION_L => null]);

        self::assertSame([self::HAIL_L => InMemoryDecryptors::ATTAINMENT], $project->inventionDecryptorsByProductTypeId());
    }

    public function testKeyThatIsNotAFinalProductOfTheProjectIsRefusedAndNothingIsChanged(): void
    {
        $project = $this->projectOf();
        $this->projectService->expects(self::never())->method('buildBom');

        try {
            $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT, self::NOT_A_FINAL_PRODUCT => InMemoryDecryptors::ATTAINMENT]);
            self::fail('A key that is not a final product is refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $project->inventionDecryptorsByProductTypeId());
        }
    }

    public function testFinalProductWithoutInventionIsRefusedAndNothingIsChanged(): void
    {
        $project = $this->projectOf();
        $this->projectService->expects(self::never())->method('buildBom');

        try {
            $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT, self::RIFTER => InMemoryDecryptors::AUGMENTATION]);
            self::fail('A product without invention is refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $project->inventionDecryptorsByProductTypeId());
        }
    }

    public function testTypeThatIsNotADecryptorOfTheSdeIsRefusedAndNothingIsChanged(): void
    {
        $project = $this->projectOf();
        $this->projectService->expects(self::never())->method('buildBom');

        try {
            $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT, self::PROTOTYPE_AMMUNITION_L => self::NOT_A_DECRYPTOR]);
            self::fail('A type that is not a decryptor is refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $project->inventionDecryptorsByProductTypeId());
        }
    }

    public function testBomThatRefusesTheChangeRestoresThePreviousChoicesAndRaisesItsMessage(): void
    {
        $project = $this->projectOf([self::HAIL_L => InMemoryDecryptors::ATTAINMENT]);
        $this->projectService->method('buildBom')->willThrowException(new \RuntimeException('A worked line would disappear.'));

        try {
            $this->choices()->change($project, [self::HAIL_L => null, self::PROTOTYPE_AMMUNITION_L => InMemoryDecryptors::AUGMENTATION]);
            self::fail('A BOM that refuses the change makes the change fail.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A worked line would disappear.', $refusal->getMessage());
            self::assertSame([self::HAIL_L => InMemoryDecryptors::ATTAINMENT], $project->inventionDecryptorsByProductTypeId());
        }
    }

    public function testTwoItemsOfTheSameProductGetTheSameChoiceAndGoBackTogetherWhenTheBomRefuses(): void
    {
        $project = $this->projectOf();
        $project->addItem((new IndustryProjectProduct())->setTypeId(self::HAIL_L)->setTypeName('Hail L')->setRuns(5));
        $this->projectService->method('buildBom')->willThrowException(new \RuntimeException('Refused.'));

        try {
            $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT]);
            self::fail('A BOM that refuses the change makes the change fail.');
        } catch (\InvalidArgumentException) {
            foreach ($project->getItems() as $item) {
                self::assertNull($item->getInventionDecryptorTypeId());
            }
        }
    }

    public function testTwoItemsOfTheSameProductAllGetTheChoice(): void
    {
        $project = $this->projectOf();
        $project->addItem((new IndustryProjectProduct())->setTypeId(self::HAIL_L)->setTypeName('Hail L')->setRuns(5));

        $this->choices()->change($project, [self::HAIL_L => InMemoryDecryptors::ATTAINMENT]);

        $choices = [];
        foreach ($project->getItems() as $item) {
            if (self::HAIL_L === $item->getTypeId()) {
                $choices[] = $item->getInventionDecryptorTypeId();
            }
        }
        self::assertSame([InMemoryDecryptors::ATTAINMENT, InMemoryDecryptors::ATTAINMENT], $choices);
    }

    private function choices(): ProjectInventionDecryptorChoices
    {
        $inventions = $this->createStub(InventionCatalog::class);
        $inventions->method('inventionOf')->willReturnCallback(
            static fn (int $typeId): ?InventionRecipe => \in_array($typeId, [self::HAIL_L, self::PROTOTYPE_AMMUNITION_L], true)
                ? new InventionRecipe(1_000 + $typeId, 2_000 + $typeId, 0.34, new Runs(10), [])
                : null,
        );

        return new ProjectInventionDecryptorChoices($this->projectService, $inventions, InMemoryDecryptors::ofTheSde());
    }

    private function projectOf(array $decryptorByProductTypeId = []): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
        foreach ([self::HAIL_L => 'Hail L', self::PROTOTYPE_AMMUNITION_L => 'Prototype Ammunition L', self::RIFTER => 'Rifter'] as $typeId => $typeName) {
            $project->addItem(
                (new IndustryProjectProduct())
                    ->setTypeId($typeId)
                    ->setTypeName($typeName)
                    ->setRuns(10)
                    ->setInventionDecryptorTypeId($decryptorByProductTypeId[$typeId] ?? null),
            );
        }

        return $project;
    }
}
