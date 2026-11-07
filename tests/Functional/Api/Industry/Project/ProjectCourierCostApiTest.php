<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\EveToken;
use App\Entity\IndustryProjectCourierContract;
use App\Enum\ProjectStatus;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-421')]
final class ProjectCourierCostApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int COURIER_CONTRACT_ID = 421_501;
    private const int JITA_STATION = 60_003_760;
    private const int WORKSHOP_STRUCTURE = 1_035_466_617_946;
    private const float COURIER_REWARD = 376_550.0;
    private const float COST_WITHOUT_COURIER = 555_000.0;
    private const float OWNER_COST_WITHOUT_COURIER = 499_500.0;

    private EsiClient&Stub $esiClient;
    private string $courierStatus = 'finished';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->owner->getMainCharacter()->setEveToken((new EveToken())
            ->setAccessToken('access-owner')
            ->setRefreshTokenEncrypted('refresh-owner')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-contracts.read_character_contracts.v1']));
        $this->em->flush();
        $this->replaceEsi();
    }

    public function testLinkingADeliveredContractCountsItsRewardInTheProjectCostAndTheOwnersCosts(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::COURIER_CONTRACT_ID]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            [self::COST_WITHOUT_COURIER + self::COURIER_REWARD, self::OWNER_COST_WITHOUT_COURIER + self::COURIER_REWARD, self::COURIER_REWARD],
            $this->costsAsMemberSees(),
        );
    }

    public function testContractStillInTransitCountsNothing(): void
    {
        $this->courierStatus = 'in_progress';
        $this->linkContractDirectly();

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::COST_WITHOUT_COURIER, self::OWNER_COST_WITHOUT_COURIER, 0.0], $this->costsAsMemberSees());
    }

    public function testNonOwnerMemberReadingTheCourierOverviewSyncsTheDeliveredContract(): void
    {
        $this->linkContractDirectly();

        $this->actAs($this->member);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [self::COST_WITHOUT_COURIER + self::COURIER_REWARD, self::OWNER_COST_WITHOUT_COURIER + self::COURIER_REWARD, self::COURIER_REWARD],
            $this->costsAsMemberSees(),
        );
    }

    public function testContractThatFailsAfterBeingCountedPutsTheTransportBackToZero(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::COURIER_CONTRACT_ID]);
        $this->courierStatus = 'failed';

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::COST_WITHOUT_COURIER, self::OWNER_COST_WITHOUT_COURIER, 0.0], $this->costsAsMemberSees());
    }

    public function testUnlinkingTheContractRemovesItsRewardFromTheCost(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::COURIER_CONTRACT_ID]);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->linkUri());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([self::COST_WITHOUT_COURIER, self::OWNER_COST_WITHOUT_COURIER, 0.0], $this->costsAsMemberSees());
    }

    public function testCompletedProjectKeepsItsCostWhateverTheContractBecomes(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::COURIER_CONTRACT_ID]);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();
        $this->courierStatus = 'failed';

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertSame(
            [self::COST_WITHOUT_COURIER + self::COURIER_REWARD, self::OWNER_COST_WITHOUT_COURIER + self::COURIER_REWARD, self::COURIER_REWARD],
            $this->costsAsMemberSees(),
        );
    }

    private function courierUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/courier';
    }

    private function linkUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/courier-contract';
    }

    /**
     * @return array{float, float, float}
     */
    private function costsAsMemberSees(): array
    {
        $this->em->clear();
        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/distribution');
        self::assertResponseStatusCodeSame(200);
        $distribution = $this->responseJson();
        $ownerRows = array_values(array_filter(
            $distribution['members'],
            static fn (array $member): bool => 'Aria Solberg' === $member['characterName'],
        ));

        return [(float) $distribution['totalProjectCost'], (float) $ownerRows[0]['totalCostsEngaged'], (float) $ownerRows[0]['courierCosts']];
    }

    private function linkContractDirectly(): void
    {
        $this->em->persist((new IndustryProjectCourierContract())
            ->setProject($this->ishtarBatch)
            ->setContractId(self::COURIER_CONTRACT_ID)
            ->setCharacterId($this->owner->getMainCharacter()->getEveCharacterId()));
        $this->em->flush();
    }

    private function replaceEsi(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('getPaginated')->willReturnCallback(fn (): array => [[
            'contract_id' => self::COURIER_CONTRACT_ID,
            'type' => 'courier',
            'status' => $this->courierStatus,
            'date_issued' => (new \DateTimeImmutable('+1 minute'))->format('Y-m-d\TH:i:sP'),
            'volume' => 25.0,
            'reward' => self::COURIER_REWARD,
            'collateral' => 5_000_000.0,
            'start_location_id' => self::JITA_STATION,
            'end_location_id' => self::WORKSHOP_STRUCTURE,
        ]]);
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        self::getContainer()->set(EsiClient::class, $this->esiClient);
        self::getContainer()->set(TokenManager::class, $tokenManager);
    }
}
