<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-335')]
final class IndustryWorkshopProjectsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string WORKSHOP_URI = '/api/industry/workshop-projects';
    private const int REVENUE_UNIT_PRICE = 10_000_000;
    private const int SOLO_FINAL_QUANTITY = 4;
    private const float SOLO_APPROVED_COST = 12_540_000.0;
    private const float SOLO_FINAL_PROFIT = 24_460_000.0;
    private const float OWNER_PROVISIONAL_PROFIT_PART = 1_998_000.0;
    private const float MEMBER_PROVISIONAL_PROFIT_PART = 222_000.0;
    private const int ISHTAR_UNITS_TO_SELL = 4;
    private const float PROFIT_TOLERANCE = 0.01;

    private User $pilotWithoutMainCharacter;
    private User $latePilot;
    private IndustryProject $outsiderBatch;
    private IndustryProject $soloAwaitingBatch;
    private IndustryProject $soloSoldBatch;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->ishtarBatch->addItem((new IndustryProjectProduct())->setTypeId(self::ISHTAR_TYPE_ID)->setTypeName('Ishtar')->setRuns(self::ISHTAR_RUNS));

        $this->outsiderBatch = (new IndustryProject())->setOwner($this->outsider)->setName('Outsider batch')->setStatus(ProjectStatus::InProgress);
        $this->em->persist($this->outsiderBatch);
        $this->addMembership($this->outsiderBatch, $this->outsider, ParticipantRole::Owner, ParticipantStatus::Accepted);

        $this->soloAwaitingBatch = $this->persistProject('Solo awaiting batch');
        $this->addMembership($this->soloAwaitingBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addJobLine($this->soloAwaitingBatch, self::HECATE_TYPE_ID, 'Hecate', 'final', 1, 1, 10, 20, null);

        $this->soloSoldBatch = $this->persistProject('Solo sold batch');
        $this->soloSoldBatch->setBrokerFeePercent(3.0)->setSalesTaxPercent(4.5);
        $soloMembership = $this->addMembership($this->soloSoldBatch, $this->owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $soloMaterialLine = $this->addMaterialLine($this->soloSoldBatch, self::NOCXIUM_TYPE_ID, 'Nocxium', 2_000, 6_270.0);
        $this->addJobLine($this->soloSoldBatch, self::HECATE_TYPE_ID, 'Hecate', 'final', 1, self::SOLO_FINAL_QUANTITY, 10, 20, null);
        $this->addContribution($soloMembership, $soloMaterialLine, ContributionType::Material, 2_000, self::SOLO_APPROVED_COST, ContributionStatus::Approved);
        $this->addSale($this->soloSoldBatch, self::HECATE_TYPE_ID, 'Hecate', self::SOLO_FINAL_QUANTITY, self::REVENUE_UNIT_PRICE, 'Jita IV - Moon 4', '2026-11-02T10:00:00+00:00');

        $this->em->flush();
    }

    public function testListIsRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerReadsOnlyTheProjectsHeIsAnAcceptedMemberOf(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            $this->sortedIds([$this->ishtarBatch, $this->hecateBatch, $this->soloAwaitingBatch, $this->soloSoldBatch]),
            $this->sortedResponseProjectIds(),
        );
    }

    public function testAcceptedMemberReadsOnlyTheProjectHeBelongsTo(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->sortedIds([$this->ishtarBatch]), $this->sortedResponseProjectIds());
    }

    public function testPendingParticipantReadsAnEmptyList(): void
    {
        $this->client->loginUser($this->pendingMember, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $this->responseJson()]);
    }

    public function testOutsiderReadsOnlyHisOwnProject(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->sortedIds([$this->outsiderBatch]), $this->sortedResponseProjectIds());
    }

    public function testMembersAreTheAcceptedParticipantsOrderedByRoleThenJoinedAt(): void
    {
        $this->enrollTeamOnIshtarBatch();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['characterId' => $this->owner->getMainCharacter()->getEveCharacterId(), 'characterName' => 'Aria Solberg', 'role' => 'owner'],
                ['characterId' => $this->ownerCorpmate->getMainCharacter()->getEveCharacterId(), 'characterName' => 'Corin Vale', 'role' => 'admin'],
                ['characterId' => $this->member->getMainCharacter()->getEveCharacterId(), 'characterName' => 'Brann Holt', 'role' => 'member'],
                ['characterId' => $this->latePilot->getMainCharacter()->getEveCharacterId(), 'characterName' => 'Fara Nyx', 'role' => 'member'],
                ['characterId' => null, 'characterName' => 'Unknown', 'role' => 'member'],
            ],
            $this->rowOf($this->ishtarBatch)['members'],
        );
    }

    public function testRowCarriesTheCorporationNameOfTheProjectOwner(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Corporation 98206101', $this->rowOf($this->ishtarBatch)['corporationName']);
    }

    public function testRowCorporationNameIsNullWhenTheOwnerHasNoCorporation(): void
    {
        $this->outsider->setMainCharacter(null);
        $this->em->flush();

        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->rowOf($this->outsiderBatch)['corporationName']);
    }

    public function testSoloProjectMembersAreThePilotAlone(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['characterId' => $this->owner->getMainCharacter()->getEveCharacterId(), 'characterName' => 'Aria Solberg', 'role' => 'owner']],
            $this->rowOf($this->hecateBatch)['members'],
        );
    }

    public function testPhasesAreTheCockpitPhasesOfTheConnectedPilot(): void
    {
        $expectedPhases = [
            ['key' => 'prepare', 'state' => 'done', 'counter' => ['done' => 1, 'total' => 1], 'blockedReason' => null],
            ['key' => 'supply', 'state' => 'blocked', 'counter' => ['done' => 0, 'total' => 3], 'blockedReason' => ['code' => 'materials_missing', 'count' => 3]],
            ['key' => 'produce', 'state' => 'current', 'counter' => ['done' => 1, 'total' => 2], 'blockedReason' => null],
            ['key' => 'sell', 'state' => 'upcoming', 'counter' => ['done' => 2, 'total' => 2], 'blockedReason' => null],
            ['key' => 'close', 'state' => 'upcoming', 'counter' => ['done' => 0, 'total' => 1], 'blockedReason' => null],
        ];

        $this->actAs($this->member);
        $this->request('GET', self::WORKSHOP_URI);
        self::assertResponseStatusCodeSame(200);
        $workshopPhases = $this->rowOf($this->ishtarBatch)['phases'];

        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/cockpit');
        self::assertResponseStatusCodeSame(200);

        self::assertSame($expectedPhases, $workshopPhases);
        self::assertSame($this->responseJson()['phases'], $workshopPhases);
    }

    public function testProfitIsAwaitingSalesWithoutAmountWhenNothingIsSold(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->soloAwaitingBatch)['profit'];
        self::assertArrayHasKey('amount', $profit);
        self::assertSame(['amount' => null, 'status' => 'awaiting_sales'], $profit);
    }

    public function testProfitIsTheProfitPartOfTheOwnerWhileProductsRemainToSell(): void
    {
        $this->ishtarJobLine->setRequiredQuantity(self::ISHTAR_UNITS_TO_SELL);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->ishtarBatch)['profit'];
        self::assertSame('provisional', $profit['status']);
        self::assertEqualsWithDelta(self::OWNER_PROVISIONAL_PROFIT_PART, $profit['amount'], self::PROFIT_TOLERANCE);
    }

    public function testProfitIsTheProfitPartOfTheMemberNotOfTheOwner(): void
    {
        $this->ishtarJobLine->setRequiredQuantity(self::ISHTAR_UNITS_TO_SELL);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->ishtarBatch)['profit'];
        self::assertSame('provisional', $profit['status']);
        self::assertEqualsWithDelta(self::MEMBER_PROVISIONAL_PROFIT_PART, $profit['amount'], self::PROFIT_TOLERANCE);
    }

    public function testProfitIsFinalWhenEverythingIsSold(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->soloSoldBatch)['profit'];
        self::assertSame('final', $profit['status']);
        self::assertEqualsWithDelta(self::SOLO_FINAL_PROFIT, $profit['amount'], self::PROFIT_TOLERANCE);
    }

    public function testProfitIsUnknownWhenTheFeesOfTheProjectAreUnknown(): void
    {
        $this->addContribution($this->hecateOwnerMembership, $this->hecateTritaniumLine, ContributionType::Material, 40_000, 176_000.0, ContributionStatus::Approved);
        $this->em->flush();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->hecateBatch)['profit'];
        self::assertArrayHasKey('amount', $profit);
        self::assertSame(['amount' => null, 'status' => 'unknown'], $profit);
    }

    public function testProfitIsUnknownForAMemberWithoutApprovedContribution(): void
    {
        $this->addMembership($this->ishtarBatch, $this->ownerCorpmate, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->client->loginUser($this->ownerCorpmate, 'api');
        $this->request('GET', self::WORKSHOP_URI);

        self::assertResponseStatusCodeSame(200);
        $profit = $this->rowOf($this->ishtarBatch)['profit'];
        self::assertArrayHasKey('amount', $profit);
        self::assertSame(['amount' => null, 'status' => 'unknown'], $profit);
    }

    private function enrollTeamOnIshtarBatch(): void
    {
        $this->latePilot = $this->persistPilot('Fara Nyx', self::MEMBER_CORPORATION_ID);
        $this->pilotWithoutMainCharacter = new User();
        $this->em->persist($this->pilotWithoutMainCharacter);

        $this->joinedAt($this->ownerMembership, '2026-10-01T08:00:00+00:00');
        $this->joinedAt($this->memberMembership, '2026-10-05T08:00:00+00:00');
        $this->joinedAt($this->addMembership($this->ishtarBatch, $this->latePilot, ParticipantRole::Member, ParticipantStatus::Accepted), '2026-10-09T08:00:00+00:00');
        $this->joinedAt($this->addMembership($this->ishtarBatch, $this->pilotWithoutMainCharacter, ParticipantRole::Member, ParticipantStatus::Accepted), '2026-10-10T08:00:00+00:00');
        $this->joinedAt($this->addMembership($this->ishtarBatch, $this->ownerCorpmate, ParticipantRole::Admin, ParticipantStatus::Accepted), '2026-10-08T08:00:00+00:00');
        $this->joinedAt($this->pendingMembership, '2026-10-02T08:00:00+00:00');
        $this->em->flush();
    }

    private function joinedAt(IndustryProjectParticipant $membership, string $joinedAt): void
    {
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'joinedAt'))->setValue($membership, new \DateTimeImmutable($joinedAt));
    }

    /**
     * @param list<IndustryProject> $projects
     *
     * @return list<string>
     */
    private function sortedIds(array $projects): array
    {
        $ids = array_map(static fn (IndustryProject $project): string => $project->getId()->toRfc4122(), $projects);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function sortedResponseProjectIds(): array
    {
        $ids = array_column($this->responseJson(), 'projectId');
        sort($ids);

        return $ids;
    }

    private function rowOf(IndustryProject $project): array
    {
        $rows = array_values(array_filter(
            $this->responseJson(),
            static fn (array $row): bool => $row['projectId'] === $project->getId()->toRfc4122(),
        ));
        self::assertCount(1, $rows);

        return $rows[0];
    }
}
