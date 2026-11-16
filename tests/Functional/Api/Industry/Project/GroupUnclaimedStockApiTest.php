<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1050')]
final class GroupUnclaimedStockApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberIsRefusedTheOwnerAllocation(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->allocationUri(), $this->donation());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOutsiderIsRefusedTheOwnerAllocation(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('POST', $this->allocationUri(), $this->donation());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerDonationWithoutAKnownContainerStockIsABadRequestAndNothingIsStored(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->allocationUri(), $this->donation());

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->storedFulfilledQuantityOfLine());
    }

    public function testUnknownAllocationModeIsRejectedByValidation(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->allocationUri(), ['bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 10, 'mode' => 'gift']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('mode', $this->violatedPaths());
    }

    public function testLaunchPriceClaimIsAcceptedByValidationAndRefusedWithoutAKnownContainerStock(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/contributions', ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 10, 'valueSource' => 'launch_price']);

        self::assertResponseStatusCodeSame(400);
    }

    private function allocationUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/contributions/unclaimed-stock';
    }

    private function donation(): array
    {
        return ['bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 10, 'mode' => 'donate'];
    }

    private function storedFulfilledQuantityOfLine(): int
    {
        $this->em->clear();

        return $this->em->find($this->tritaniumLine::class, $this->tritaniumLine->getId())->getFulfilledQuantity();
    }
}
