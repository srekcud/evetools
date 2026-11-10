<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\JitaMarketService;
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

    #[Group('issue-538')]
    public function testListExposesThePercentileSourceOfTheMaterialContribution(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(200);
        $materialContributions = array_values(array_filter($this->responseJson(), static fn (array $contribution): bool => 'material' === $contribution['type']));
        self::assertCount(1, $materialContributions);
        self::assertArrayHasKey('estimatedValueSource', $materialContributions[0]);
        self::assertSame(
            ['market' => 'jita', 'side' => 'sell', 'method' => 'percentile', 'coveragePercent' => null, 'percentile' => 5.0],
            $materialContributions[0]['estimatedValueSource'],
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

    #[Group('issue-363')]
    public function testMaterialContributionIsValuedAtTheJitaPriceOfTheDepositDayTimesTheQuantityNotAtTheBomPrice(): void
    {
        $this->givenJitaPercentilePrices([34 => 7.0]);
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [['Brann Holt', 'Tritanium', 'material', 2_500, 17_500.0, 'pending', null, null, null]],
            $this->contributionRows([$this->responseJson()]),
        );
        self::assertSame([4, 0], [$this->storedContributionCount(), $this->storedFulfilledQuantityOf($this->tritaniumLine)]);
    }

    #[Group('issue-363')]
    public function testMaterialContributionWithAnEnteredValueIsRefused(): void
    {
        $this->givenJitaPercentilePrices([34 => 7.0]);
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500, 'estimatedValue' => 12_000]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'A material contribution takes no entered value: it is valued at the Jita price of the deposit day.'], $this->errorDetail());
        self::assertSame(3, $this->storedContributionCount());
    }

    #[Group('issue-363')]
    public function testMaterialContributionWithoutAJitaPriceIsRefusedWithoutFallbackOnTheBomPrice(): void
    {
        $this->givenJitaPercentilePrices([34 => null]);
        $this->client->loginUser($this->member, 'api');
        $this->request('POST', $this->contributionsUri(), ['type' => 'material', 'bomItemId' => $this->idOf($this->tritaniumLine), 'quantity' => 2_500]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'No market price for type 34: no Jita price for the deposit day.'], $this->errorDetail());
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

    #[Group('issue-510')]
    public function testAdminRejectsTheApprovedDeclaredMaterialThatDuplicatesADerivedOneAndTheLineIsDowngraded(): void
    {
        $admin = $this->givenAnAcceptedAdmin();
        $declared = $this->givenAnApprovedDeclaredMaterialOfTheOwnerDoublingADerivedOne();

        $this->client->loginUser($admin, 'api');
        $this->request('PATCH', $this->contributionUri($declared), ['status' => 'rejected']);

        self::assertResponseStatusCodeSame(200);
        $contribution = $this->responseJson();
        self::assertSame(['rejected', 'Kira Doran', 400], [$contribution['status'], $contribution['reviewedByCharacterName'], $contribution['quantity']]);
        self::assertArrayHasKey('duplicatesDerived', $contribution);
        self::assertFalse($contribution['duplicatesDerived']);
        self::assertSame(['rejected', 600], [$this->storedStatusOf($declared), $this->storedFulfilledQuantityOf($this->tritaniumLine)]);
    }

    #[Group('issue-510')]
    public function testListFlagsOnlyTheApprovedDeclaredContributionThatDuplicatesADerivedOne(): void
    {
        $declared = $this->givenAnApprovedDeclaredMaterialOfTheOwnerDoublingADerivedOne();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->contributionsUri());

        self::assertResponseStatusCodeSame(200);
        $flagsById = [];
        foreach ($this->responseJson() as $contribution) {
            self::assertArrayHasKey('duplicatesDerived', $contribution);
            $flagsById[$contribution['id']] = $contribution['duplicatesDerived'];
        }
        self::assertTrue($flagsById[$this->idOf($declared)]);
        self::assertSame([$this->idOf($declared)], array_keys(array_filter($flagsById)));
    }

    #[Group('issue-510')]
    public function testRejectionOfAnApprovedDeclaredContributionWithoutDerivedTwinIsRefused(): void
    {
        $admin = $this->givenAnAcceptedAdmin();
        $declared = $this->addContribution($this->ownerMembership, $this->tritaniumLine, ContributionType::Material, 400, 2_800.0, ContributionStatus::Approved);
        $this->tritaniumLine->setFulfilledQuantity(400);
        $this->em->flush();

        $this->client->loginUser($admin, 'api');
        $this->request('PATCH', $this->contributionUri($declared), ['status' => 'rejected']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([400, 'Cannot reject a contribution with status "approved"'], $this->errorDetail());
        self::assertSame(['approved', 400], [$this->storedStatusOf($declared), $this->storedFulfilledQuantityOf($this->tritaniumLine)]);
    }

    private function givenAnAcceptedAdmin(): User
    {
        $admin = $this->persistPilot('Kira Doran', 98_510_001);
        $this->addMembership($this->ishtarBatch, $admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->em->flush();

        return $admin;
    }

    private function givenAnApprovedDeclaredMaterialOfTheOwnerDoublingADerivedOne(): IndustryProjectContribution
    {
        $declared = $this->addContribution($this->ownerMembership, $this->tritaniumLine, ContributionType::Material, 400, 2_800.0, ContributionStatus::Approved);
        $derived = $this->addContribution($this->ownerMembership, $this->tritaniumLine, ContributionType::Material, 600, 4_200.0, ContributionStatus::Approved);
        $derived->setIsAutoDetected(true)->setIsVerified(true);
        $this->tritaniumLine->setFulfilledQuantity(1_000);
        $this->em->flush();

        return $declared;
    }

    private function givenJitaPercentilePrices(array $pricesByTypeId): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getCheapestPercentilePrices')->willReturn($pricesByTypeId);
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
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
