<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectProduct;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\IndustryProjectService;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1318')]
final class MemberOwnStockSupplyApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int RAM_IN_THE_HANGAR_OF_THE_MEMBER = 100;
    private const float RAM_JITA_PRICE = 1_000.0;
    private const float TRITANIUM_JITA_PRICE = 5.0;
    private const int JITA_LOCATION_ID = 60_003_760;

    private IndustryProject $supplyBatch;
    private IndustryProjectBomLine $ramLine;
    private IndustryProjectBomLine $ishtarLine;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->seedJitaSellOrderBooks([
            self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => self::RAM_JITA_PRICE, 'volume' => 100_000]],
            self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_JITA_PRICE, 'volume' => 10_000_000]],
        ]);

        $this->supplyBatch = $this->persistProject('Ishtar supply batch');
        $this->supplyBatch->setStatus(ProjectStatus::Draft)->enrolOwner();
        $this->addMembership($this->supplyBatch, $this->member, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->supplyBatch->addItem((new IndustryProjectProduct())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setMeLevel(4)
            ->setTeLevel(8)
            ->setRuns(self::ISHTAR_RUNS)
            ->setSortOrder(0));
        $this->em->flush();
        self::getContainer()->get(IndustryProjectService::class)->buildBom($this->supplyBatch, $this->owner);
        $this->em->flush();

        $this->ramLine = $this->lineOfType(self::RAM_STARSHIP_TECH_TYPE_ID, 'component');
        $this->ishtarLine = $this->lineOfType(self::ISHTAR_TYPE_ID, 'final');
        $this->memberHangarHolds(self::RAM_STARSHIP_TECH_TYPE_ID, self::RAM_IN_THE_HANGAR_OF_THE_MEMBER);
    }

    public function testMemberSupplyWithinTheirOwnStockIsApprovedAtOnceAndReducesTheRunsOfTheLine(): void
    {
        $runsBefore = $this->ramLine->getRuns();

        $this->actAs($this->member);
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->ramLine), 'quantity' => self::RAM_IN_THE_HANGAR_OF_THE_MEMBER]);

        self::assertResponseStatusCodeSame(201);
        $contribution = $this->responseJson();
        self::assertSame(['material', self::RAM_IN_THE_HANGAR_OF_THE_MEMBER, 'approved', 'Brann Holt'], [$contribution['type'], $contribution['quantity'], $contribution['status'], $contribution['memberCharacterName']]);
        $this->em->clear();
        $line = $this->em->find(IndustryProjectBomLine::class, $this->ramLine->getId());
        self::assertSame([3, 2, 300, 100, 200], [$runsBefore, $line->getRuns(), $line->getRequiredQuantity(), $line->getFulfilledQuantity(), $line->getRemainingQuantity()]);
        self::assertSame([[ContributionStatus::Approved, self::RAM_IN_THE_HANGAR_OF_THE_MEMBER]], $this->storedSupplies());
    }

    public function testMemberSupplyAboveTheirOwnStockIsRefusedWithTheAvailableQuantityAndNothingIsRecorded(): void
    {
        $runsBefore = $this->ramLine->getRuns();

        $this->actAs($this->member);
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->ramLine), 'quantity' => self::RAM_IN_THE_HANGAR_OF_THE_MEMBER + 1]);

        self::assertResponseStatusCodeSame(400);
        [$status, $detail] = $this->errorDetail();
        self::assertSame(400, $status);
        self::assertStringContainsString('exceeds your own stock', $detail);
        self::assertStringContainsString('(100)', $detail);
        $this->em->clear();
        $line = $this->em->find(IndustryProjectBomLine::class, $this->ramLine->getId());
        self::assertSame([$runsBefore, 0, []], [$line->getRuns(), $line->getFulfilledQuantity(), $this->storedSupplies()]);
    }

    public function testMemberSupplyOfTheFinalProductIsRefusedAndNothingIsRecorded(): void
    {
        $this->memberHangarHolds(self::ISHTAR_TYPE_ID, 1);

        $this->actAs($this->member);
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->ishtarLine), 'quantity' => 1]);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        $line = $this->em->find(IndustryProjectBomLine::class, $this->ishtarLine->getId());
        self::assertSame([0, []], [$line->getFulfilledQuantity(), $this->storedSupplies()]);
    }

    private function lineOfType(int $typeId, string $jobGroup): IndustryProjectBomLine
    {
        foreach ($this->supplyBatch->getBomItems() as $line) {
            if ($line->isJob() && $line->getTypeId() === $typeId && $line->getJobGroup() === $jobGroup) {
                return $line;
            }
        }

        throw new \LogicException(sprintf('The plan has no %s job line for the type %d.', $jobGroup, $typeId));
    }

    private function memberHangarHolds(int $typeId, int $quantity): void
    {
        $asset = (new CachedAsset())
            ->setItemId(1_070_800_100_000 + $typeId)
            ->setTypeId($typeId)
            ->setTypeName((string) $typeId)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_LOCATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setSolarSystemName('Jita')
            ->setLocationType('station')
            ->setCharacter($this->member->getMainCharacter())
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);
        $this->em->flush();
    }

    private function storedSupplies(): array
    {
        $contributions = $this->em->getRepository(IndustryProjectContribution::class)->findBy(['project' => $this->supplyBatch->getId()]);

        return array_map(
            static fn (IndustryProjectContribution $contribution): array => [$contribution->getStatus(), $contribution->getQuantity()],
            array_values(array_filter($contributions, static fn (IndustryProjectContribution $contribution): bool => ContributionType::Material === $contribution->getType())),
        );
    }

    private function contributionsUri(): string
    {
        return $this->projectUri($this->supplyBatch).'/contributions';
    }
}
