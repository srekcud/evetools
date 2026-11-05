<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Repository\IndustryProjectPurchaseRepository;
use App\State\Processor\Industry\DeletePurchaseProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(DeletePurchaseProcessor::class)]
#[Group('issue-362')]
final class DeletePurchaseProcessorTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000001';
    private const string RAM_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000021';
    private const string TRITANIUM_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000022';
    private const string UNKNOWN_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-362000000099';
    private const int RAM_TYPE_ID = 11478;
    private const int PURCHASED_RAMS = 50;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PURCHASED_TRITANIUM = 1_000;

    private array $entityManagerCalls = [];

    public function testPurchaseDesignatedByTheResourceIsRemovedFromItsLineThenFlushedRatherThanThePurchaseOfTheUriVariables(): void
    {
        $ramLine = (new IndustryProjectBomLine())->setTypeId(self::RAM_TYPE_ID)->setTypeName('R.A.M.- Starship Tech')->setRequiredQuantity(400);
        $ramPurchase = (new IndustryProjectPurchase())->setTypeId(self::RAM_TYPE_ID)->setQuantity(self::PURCHASED_RAMS);
        $ramLine->addPurchase($ramPurchase);
        $tritaniumPurchase = (new IndustryProjectPurchase())->setTypeId(self::TRITANIUM_TYPE_ID)->setQuantity(self::PURCHASED_TRITANIUM);

        $this->processorFinding([self::RAM_PURCHASE_ID => $ramPurchase, self::TRITANIUM_PURCHASE_ID => $tritaniumPurchase])
            ->process($this->purchaseResource(self::RAM_PURCHASE_ID), new Delete(), ['projectId' => self::PROJECT_ID, 'id' => self::TRITANIUM_PURCHASE_ID]);

        self::assertSame([['remove', $ramPurchase], ['flush']], $this->entityManagerCalls);
        self::assertSame([0, 0], [$ramLine->getPurchases()->count(), $ramLine->getPurchasedQuantity()]);
    }

    public function testResourceDesignatingNoKnownPurchaseIsNotFoundWithoutRemovingNorFlushing(): void
    {
        $processor = $this->processorFinding([self::RAM_PURCHASE_ID => (new IndustryProjectPurchase())->setTypeId(self::RAM_TYPE_ID)]);

        try {
            $processor->process($this->purchaseResource(self::UNKNOWN_PURCHASE_ID), new Delete(), ['projectId' => self::PROJECT_ID, 'id' => self::RAM_PURCHASE_ID]);
            self::fail('A resource designating no known purchase must be answered as not found');
        } catch (NotFoundHttpException $notFound) {
            self::assertSame('Purchase not found', $notFound->getMessage());
        }

        self::assertSame([], $this->entityManagerCalls);
    }

    private function processorFinding(array $purchasesById): DeletePurchaseProcessor
    {
        $purchaseRepository = $this->createStub(IndustryProjectPurchaseRepository::class);
        $purchaseRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProjectPurchase => $purchasesById[(string) $id] ?? null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->entityManagerCalls[] = ['remove', $entity];
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->entityManagerCalls[] = ['flush'];
        });

        return new DeletePurchaseProcessor($entityManager, $purchaseRepository);
    }

    private function purchaseResource(string $purchaseId): ProjectPurchaseResource
    {
        $resource = new ProjectPurchaseResource();
        $resource->id = $purchaseId;

        return $resource;
    }
}
