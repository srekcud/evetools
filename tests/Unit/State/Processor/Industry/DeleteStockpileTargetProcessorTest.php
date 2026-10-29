<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\State\Processor\Industry\DeleteStockpileTargetProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeleteStockpileTargetProcessor::class)]
final class DeleteStockpileTargetProcessorTest extends TestCase
{
    private const int WIDGET = 3001;
    private const int GADGET = 3004;
    private const int FRAME = 3002;
    private const int ORE = 3003;

    private array $targets = [];
    private IndustryStockpileImportRepository&MockObject $importRepository;
    private DeleteStockpileTargetProcessor $processor;
    private User $pilot;

    protected function setUp(): void
    {
        $this->pilot = $this->createStub(User::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);

        $targetRepository = $this->createStub(IndustryStockpileTargetRepository::class);
        $targetRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryStockpileTarget => $this->targets[$id->toRfc4122()] ?? null,
        );
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => array_values($this->targets));
        $targetRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => $this->targetsMatching($criteria));
        $targetRepository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?IndustryStockpileTarget => $this->targetsMatching($criteria)[0] ?? null);
        $targetRepository->method('count')->willReturnCallback(fn (array $criteria): int => \count($this->targetsMatching($criteria)));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $target): void {
            unset($this->targets[$target->getId()->toRfc4122()]);
        });

        $this->importRepository = $this->createMock(IndustryStockpileImportRepository::class);
        $this->processor = new DeleteStockpileTargetProcessor($security, $targetRepository, $entityManager, $this->importRepository);
    }

    public function testDeletingTheLastTargetOfASourceProductDeletesTheStockpileImportsOfThatProduct(): void
    {
        $widgetOre = $this->addTarget(1, self::ORE, self::WIDGET);
        $this->addTarget(2, self::FRAME, self::GADGET);

        $this->importRepository->expects(self::once())->method('deleteAllForUserAndProduct')->with($this->pilot, self::WIDGET);

        $this->delete($widgetOre);
    }

    public function testTargetOfAnotherUserWithTheSameSourceProductDoesNotKeepMyImports(): void
    {
        $widgetOre = $this->addTarget(1, self::ORE, self::WIDGET);
        $this->addTarget(2, self::FRAME, self::WIDGET)->setUser($this->createStub(User::class));

        $this->importRepository->expects(self::once())->method('deleteAllForUserAndProduct')->with($this->pilot, self::WIDGET);

        $this->delete($widgetOre);
    }

    public function testDeletingATargetWhileAnotherTargetOfTheSameSourceProductRemainsKeepsItsStockpileImports(): void
    {
        $widgetOre = $this->addTarget(1, self::ORE, self::WIDGET);
        $this->addTarget(2, self::FRAME, self::WIDGET);

        $this->importRepository->expects(self::never())->method('deleteAllForUserAndProduct');

        $this->delete($widgetOre);
    }

    public function testDeletingAManualTargetKeepsEveryStockpileImport(): void
    {
        $manualOre = $this->addTarget(1, self::ORE, null);
        $this->addTarget(2, self::FRAME, self::WIDGET);

        $this->importRepository->expects(self::never())->method('deleteAllForUserAndProduct');

        $this->delete($manualOre);
    }

    public function testClearingEveryTargetOneByOneDeletesTheStockpileImportsOfEachSourceProduct(): void
    {
        $widgetOre = $this->addTarget(1, self::ORE, self::WIDGET);
        $widgetFrame = $this->addTarget(2, self::FRAME, self::WIDGET);
        $gadget = $this->addTarget(3, self::GADGET, self::GADGET);
        $deletedProducts = [];
        $this->importRepository->method('deleteAllForUserAndProduct')->willReturnCallback(
            static function (User $user, int $productTypeId) use (&$deletedProducts): void {
                $deletedProducts[] = $productTypeId;
            },
        );

        $this->delete($widgetOre);
        $this->delete($widgetFrame);
        $this->delete($gadget);

        self::assertSame([self::WIDGET, self::GADGET], $deletedProducts);
    }

    #[Group('issue-200')]
    public function testTargetIdThatIsNotAUuidIsNotFoundAndKeepsEveryTargetAndStockpileImport(): void
    {
        $this->addTarget(4, self::GADGET, self::GADGET);
        $this->importRepository->expects(self::never())->method('deleteAllForUserAndProduct');

        try {
            $this->processor->process(null, new Delete(), ['id' => 'target-9']);
            $outcome = 'deleted';
        } catch (HttpExceptionInterface $rejection) {
            $outcome = $rejection->getStatusCode().' '.$rejection->getMessage();
        } catch (\Throwable $throwable) {
            $outcome = $throwable::class.': '.$throwable->getMessage();
        }

        self::assertSame(['404 Stockpile target not found', 1], [$outcome, \count($this->targets)]);
    }

    private function addTarget(int $selfId, int $typeId, ?int $sourceProductTypeId): IndustryStockpileTarget
    {
        $target = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setTargetQuantity(10 * $selfId)
            ->setStage('raw_material')
            ->setSourceProductTypeId($sourceProductTypeId);
        $id = Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', $selfId));
        (new \ReflectionProperty(IndustryStockpileTarget::class, 'id'))->setValue($target, $id);
        $this->targets[$id->toRfc4122()] = $target;

        return $target;
    }

    private function delete(IndustryStockpileTarget $target): void
    {
        $this->processor->process(null, new Delete(), ['id' => $target->getId()->toRfc4122()]);
    }

    private function targetsMatching(array $criteria): array
    {
        return array_values(array_filter(
            $this->targets,
            static function (IndustryStockpileTarget $target) use ($criteria): bool {
                foreach ($criteria as $field => $value) {
                    if ($target->{'get'.ucfirst($field)}() !== $value) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
