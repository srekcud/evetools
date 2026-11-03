<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Industry\StepPurchaseResource;
use App\Entity\IndustryStepPurchase;
use App\Repository\IndustryStepPurchaseRepository;
use App\State\Processor\Industry\DeletePurchaseProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(DeletePurchaseProcessor::class)]
#[Group('issue-206')]
final class DeletePurchaseProcessorTest extends TestCase
{
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000001';
    private const string STEP_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000011';
    private const string ISOTOPE_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000021';
    private const string FUEL_BLOCK_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000022';
    private const string UNKNOWN_PURCHASE_ID = '0192f0c4-7b1e-7a3c-9d2e-206000000099';
    private const int ISOTOPE_TYPE_ID = 16274;
    private const int PURCHASED_ISOTOPES = 2_160;
    private const int FUEL_BLOCK_TYPE_ID = 4246;
    private const int PURCHASED_FUEL_BLOCKS = 40;

    private array $entityManagerCalls = [];

    public function testPurchaseDesignatedByTheResourceIdIsRemovedThenFlushedRatherThanThePurchaseOfTheUriVariables(): void
    {
        $isotopePurchase = (new IndustryStepPurchase())->setTypeId(self::ISOTOPE_TYPE_ID)->setQuantity(self::PURCHASED_ISOTOPES);
        $fuelBlockPurchase = (new IndustryStepPurchase())->setTypeId(self::FUEL_BLOCK_TYPE_ID)->setQuantity(self::PURCHASED_FUEL_BLOCKS);

        $this->processorFinding([self::ISOTOPE_PURCHASE_ID => $isotopePurchase, self::FUEL_BLOCK_PURCHASE_ID => $fuelBlockPurchase])
            ->process($this->purchaseResource(self::ISOTOPE_PURCHASE_ID), new Delete(), $this->uriVariables(self::FUEL_BLOCK_PURCHASE_ID));

        self::assertSame([['remove', $isotopePurchase], ['flush']], $this->entityManagerCalls);
    }

    public function testResourceDesignatingNoKnownPurchaseIsNotFoundWithoutRemovingNorFlushing(): void
    {
        $processor = $this->processorFinding([self::ISOTOPE_PURCHASE_ID => (new IndustryStepPurchase())->setTypeId(self::ISOTOPE_TYPE_ID)]);

        try {
            $processor->process($this->purchaseResource(self::UNKNOWN_PURCHASE_ID), new Delete(), $this->uriVariables(self::ISOTOPE_PURCHASE_ID));
            self::fail('A resource designating no known purchase must be answered as not found');
        } catch (NotFoundHttpException $notFound) {
            self::assertSame('Purchase not found', $notFound->getMessage());
        }

        self::assertSame([], $this->entityManagerCalls);
    }

    private function processorFinding(array $purchasesById): DeletePurchaseProcessor
    {
        $purchaseRepository = $this->createStub(IndustryStepPurchaseRepository::class);
        $purchaseRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryStepPurchase => $purchasesById[(string) $id] ?? null,
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

    private function purchaseResource(string $purchaseId): StepPurchaseResource
    {
        $resource = new StepPurchaseResource();
        $resource->id = $purchaseId;
        $resource->stepId = self::STEP_ID;

        return $resource;
    }

    private function uriVariables(string $purchaseId): array
    {
        return ['id' => self::PROJECT_ID, 'stepId' => self::STEP_ID, 'purchaseId' => $purchaseId];
    }
}
