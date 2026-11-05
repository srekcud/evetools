<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\GroupIndustry;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class GroupContributionApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
    }

    public function testAcceptedMemberListsTheContributionsOfTheProjectOnly(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['Brann Holt', 'Morphite', 'material', 3, 55_500.0, 'approved', 'Aria Solberg', self::REVIEWED_AT, null],
                ['Brann Holt', 'R.A.M.- Starship Tech', 'job_install', 300, 180_000.0, 'pending', null, null, 'Three runs in Jita IV'],
                ['Aria Solberg', 'Ishtar', 'job_install', 2, 499_500.0, 'approved', 'Aria Solberg', self::REVIEWED_AT, null],
            ],
            $this->contributionRows($this->rowsSortedBy('estimatedValue', $this->responseJson())),
        );
    }

    public function testOutsiderIsRefusedTheContributionList(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testPendingMemberIsRefusedTheContributionList(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testMaterialContributionOnALineWithABomPriceIsValuedAtTheBomPriceTimesTheQuantity(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [['Brann Holt', 'Tritanium', 'material', 2_500, 10_625.0, 'pending', null, null, null]],
            $this->contributionRows([$this->responseJson()]),
        );
        self::assertSame([4, 0], [$this->storedContributionCount(), $this->storedFulfilledQuantityOf($this->tritaniumLine)]);
    }

    public function testMaterialContributionWithAnEnteredValueOnALineWithABomPriceIsRefused(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500, 'estimatedValue' => 12_000]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Type 34 has a BOM price: a material contribution is valued from it.'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    public function testSecondMaterialContributionOfTheSameMemberOnTheSameLineIsRefused(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->morphiteLine), 'quantity' => 5]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'A contribution of this type is already pending or approved for this item'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    public function testOutsiderCannotContribute(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You must be an accepted member to contribute'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    public function testPendingMemberCannotContribute(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You must be an accepted member to contribute'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    public function testContributionOnABomLineOfAnotherProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->hecateTritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'BOM item not found'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    public function testContributionWhoseBomLineIdentifierIsNotAUuidIsRejectedOnTheBomLine(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => self::NOT_A_UUID, 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['bomItemId'], 3], [$this->violatedPaths(), $this->storedContributionCount()]);
    }

    public function testApprovedJobInstallOfThreeRunsFulfilsTheThreeHundredUnitsOfTheComponentJobLine(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(200);
        $contribution = $this->responseJson();
        self::assertSame(['approved', 'Aria Solberg', 300], [$contribution['status'], $contribution['reviewedByCharacterName'], $contribution['quantity']]);
        self::assertSame(300, $this->storedFulfilledQuantityOf($this->ramJobLine));
    }

    public function testRejectedJobInstallLeavesTheComponentJobLineUnfulfilled(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall), ['status' => 'rejected']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['rejected', 'Aria Solberg'], [$this->responseJson()['status'], $this->responseJson()['reviewedByCharacterName']]);
        self::assertSame(0, $this->storedFulfilledQuantityOf($this->ramJobLine));
    }

    public function testApprovalOfAnAlreadyApprovedContributionIsRefused(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->contributionUri($this->memberMorphiteContribution), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot approve a contribution with status "approved"'], $this->errorDetail());
        self::assertSame(3, $this->storedFulfilledQuantityOf($this->morphiteLine));
    }

    public function testOutsiderCannotReviewAContribution(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['pending', 0], [$this->storedStatusOf($this->memberRamJobInstall), $this->storedFulfilledQuantityOf($this->ramJobLine)]);
    }

    public function testPlainMemberCannotReviewHisOwnContribution(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('PATCH', $this->contributionUri($this->memberRamJobInstall), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only project owner or admin can perform this action'], $this->errorDetail());
        self::assertSame(['pending', 0], [$this->storedStatusOf($this->memberRamJobInstall), $this->storedFulfilledQuantityOf($this->ramJobLine)]);
    }

    public function testReviewOfAContributionThroughAnotherProjectOfTheOwnerIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->projectUri($this->hecateBatch).'/contributions/'.$this->idOf($this->memberRamJobInstall), ['status' => 'approved']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Contribution not found'], $this->errorDetail());
        self::assertSame(['pending', 0], [$this->storedStatusOf($this->memberRamJobInstall), $this->storedFulfilledQuantityOf($this->ramJobLine)]);
    }

    public function testReviewOfAContributionWhoseIdIsNotAUuidIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('PATCH', $this->contributionsUri().'/'.self::NOT_A_UUID, ['status' => 'approved']);

        self::assertResponseStatusCodeSame(404);
    }

    private function contributionsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/contributions';
    }

    private function contributionUri(IndustryProjectContribution $contribution): string
    {
        return $this->contributionsUri().'/'.$this->idOf($contribution);
    }

    private function contributionRows(array $contributions): array
    {
        return array_map(
            static fn (array $contribution): array => [
                $contribution['memberCharacterName'],
                $contribution['bomItemTypeName'],
                $contribution['type'],
                $contribution['quantity'],
                (float) $contribution['estimatedValue'],
                $contribution['status'],
                $contribution['reviewedByCharacterName'] ?? null,
                $contribution['reviewedAt'] ?? null,
                $contribution['note'] ?? null,
            ],
            $contributions,
        );
    }

    private function storedContributionCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryProjectContribution::class)->count(['project' => $this->ishtarBatch->getId()]);
    }

    private function storedFulfilledQuantityOf(IndustryProjectBomLine $line): ?int
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectBomLine::class, $line->getId())?->getFulfilledQuantity();
    }

    private function storedStatusOf(IndustryProjectContribution $contribution): ?string
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectContribution::class, $contribution->getId())?->getStatus()->value;
    }
}
