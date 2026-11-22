<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1205')]
final class IndustryProjectInventionDecryptorPersistenceTest extends IntegrationTestCase
{
    private const int HAIL_L = 12_779;
    private const int PROTOTYPE_AMMUNITION_L = 990_003;
    private const int RIFTER = 587;
    private const int ATTAINMENT = 34_202;
    private const int AUGMENTATION = 34_203;

    public function testInventionDecryptorsOfTheProductsAreStoredAndOnlyTheNonNullChoicesAreReadByProductTypeId(): void
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName('Decryptor choices');
        $project->enrolOwner();
        $project->addItem($this->productOf(self::HAIL_L, 'Hail L', self::ATTAINMENT, 0));
        $project->addItem($this->productOf(self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', self::AUGMENTATION, 1));
        $project->addItem($this->productOf(self::RIFTER, 'Rifter', null, 2));
        $this->em->persist($project);
        $this->flushAndClear();

        $reloaded = self::getContainer()->get(IndustryProjectRepository::class)->find($project->getId());

        self::assertSame(
            [self::ATTAINMENT, self::AUGMENTATION, null],
            array_map(static fn (IndustryProjectProduct $product): ?int => $product->getInventionDecryptorTypeId(), $reloaded->getItems()->toArray()),
        );
        self::assertSame(
            [self::HAIL_L => self::ATTAINMENT, self::PROTOTYPE_AMMUNITION_L => self::AUGMENTATION],
            $reloaded->inventionDecryptorsByProductTypeId(),
        );
    }

    public function testRemovedChoiceIsStoredAsNullAndLeavesTheReadMapOfTheOtherProducts(): void
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName('Decryptor choices');
        $project->enrolOwner();
        $project->addItem($this->productOf(self::HAIL_L, 'Hail L', self::ATTAINMENT, 0));
        $project->addItem($this->productOf(self::PROTOTYPE_AMMUNITION_L, 'Prototype Ammunition L', self::AUGMENTATION, 1));
        $this->em->persist($project);
        $this->flushAndClear();

        $stored = self::getContainer()->get(IndustryProjectRepository::class)->find($project->getId());
        $stored->getItems()->first()->setInventionDecryptorTypeId(null);
        $this->flushAndClear();
        $reloaded = self::getContainer()->get(IndustryProjectRepository::class)->find($project->getId());

        self::assertSame([self::PROTOTYPE_AMMUNITION_L => self::AUGMENTATION], $reloaded->inventionDecryptorsByProductTypeId());
    }

    private function productOf(int $typeId, string $typeName, ?int $inventionDecryptorTypeId, int $sortOrder): IndustryProjectProduct
    {
        return (new IndustryProjectProduct())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRuns(10)
            ->setMeLevel(2)
            ->setTeLevel(4)
            ->setSortOrder($sortOrder)
            ->setInventionDecryptorTypeId($inventionDecryptorTypeId);
    }
}
