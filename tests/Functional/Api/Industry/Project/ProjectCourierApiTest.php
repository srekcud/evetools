<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\EveToken;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\IndustryProjectPurchase;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\MapSolarSystemJump;
use App\Enum\ParticipantRole;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-351')]
final class ProjectCourierApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int URLEN_SYSTEM_ID = 30_000_139;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int JITA_STATION = 60_003_760;
    private const int WORKSHOP_STRUCTURE = 1_035_466_617_946;
    private const int IN_TRANSIT_CONTRACT_ID = 351_501;
    private const int OUTSTANDING_CONTRACT_ID = 351_502;
    private const int TOO_OLD_CONTRACT_ID = 351_503;
    private const int ITEM_EXCHANGE_CONTRACT_ID = 351_504;
    private const int CATEGORY_ID = 999_351_001;
    private const int GROUP_ID = 999_351_002;
    private const float TRITANIUM_VOLUME = 0.01;
    private const float MORPHITE_VOLUME = 0.5;

    private EsiClient&Stub $esiClient;
    private array $contractsByEveCharacterId = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->memberMembership->setRole(ParticipantRole::Admin);
        $this->seedVolumesRouteAndFavoriteSystem();
        $this->purchaseOn($this->tritaniumLine, 1_000, 4_400.0);
        $this->purchaseOn($this->tritaniumLine, 500, 2_150.0);
        $this->purchaseOn($this->morphiteLine, 20, 370_000.0);
        $this->giveTheOwnerPilotAToken();
        $this->em->flush();
        $this->replaceEsi();
        $this->contractsByEveCharacterId[$this->ownerEveCharacterId()] = [
            $this->contract(self::IN_TRANSIT_CONTRACT_ID, 'courier', 'in_progress', '+1 minute', 40.0, 9_000.0, 5_000_000.0),
            $this->contract(self::OUTSTANDING_CONTRACT_ID, 'courier', 'outstanding', '+2 minutes', 25.0, 7_500.0, 376_550.0),
            $this->contract(self::TOO_OLD_CONTRACT_ID, 'courier', 'finished', '-2 days', 25.0, 7_500.0, 376_550.0),
            $this->contract(self::ITEM_EXCHANGE_CONTRACT_ID, 'item_exchange', 'outstanding', '+3 minutes', 25.0, 7_500.0, 376_550.0),
        ];
    }

    public function testOwnerReadsTheQuoteTheLinesWithoutLinkAndTheCandidatesOfTheProject(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(
            [
                $this->ishtarBatch->getId()->toRfc4122(), 25.0, 376_550, 7_500, 2, 'ready', [], 3, 7,
                self::JITA_SYSTEM_ID, 'Jita', self::PERIMETER_SYSTEM_ID, 'Perimeter', true, null,
            ],
            [
                $body['id'], $body['volumeCubicMeters'], $body['collateral'], $body['reward'], $body['jumps'], $body['quoteStatus'], $body['missingVolumeTypeIds'],
                $body['daysToComplete'], $body['expirationDays'],
                $body['originSolarSystemId'], $body['originSolarSystemName'], $body['workshopSolarSystemId'], $body['workshopSolarSystemName'], $body['canLink'], $body['contract'],
            ],
        );
        self::assertSame(
            [[self::TRITANIUM_TYPE_ID, 1_500, 'not_shipped'], [self::MORPHITE_TYPE_ID, 20, 'not_shipped']],
            $this->linesOf($body),
        );
        self::assertSame(
            [
                [self::OUTSTANDING_CONTRACT_ID, $this->ownerEveCharacterId(), 'waiting', 25.0, 7_500.0, 376_550.0, self::JITA_STATION, self::WORKSHOP_STRUCTURE, true],
                [self::IN_TRANSIT_CONTRACT_ID, $this->ownerEveCharacterId(), 'in_transit', 40.0, 9_000.0, 5_000_000.0, self::JITA_STATION, self::WORKSHOP_STRUCTURE, false],
            ],
            array_map(static fn (array $candidate): array => [
                $candidate['contractId'], $candidate['characterId'], $candidate['state'], $candidate['volume'], $candidate['reward'], $candidate['collateral'],
                $candidate['startLocationId'], $candidate['endLocationId'], $candidate['matchesQuote'],
            ], $body['candidates']),
        );
    }

    public function testOwnerLinksTheInTransitContractThenReadsItsStateOnTheContractAndOnEveryLine(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::IN_TRANSIT_CONTRACT_ID]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([[self::IN_TRANSIT_CONTRACT_ID, $this->ownerEveCharacterId()]], $this->storedLinks());

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(
            [self::IN_TRANSIT_CONTRACT_ID, $this->ownerEveCharacterId(), 'in_transit', null, 40.0, 9_000.0, 5_000_000.0],
            [
                $body['contract']['contractId'], $body['contract']['characterId'], $body['contract']['state'], $body['contract']['unknownReason'],
                $body['contract']['volume'], $body['contract']['reward'], $body['contract']['collateral'],
            ],
        );
        self::assertSame(
            [[self::TRITANIUM_TYPE_ID, 1_500, 'in_transit'], [self::MORPHITE_TYPE_ID, 20, 'in_transit']],
            $this->linesOf($body),
        );
    }

    public function testLinkingAnotherContractReplacesThePreviousLink(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::IN_TRANSIT_CONTRACT_ID]);
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::OUTSTANDING_CONTRACT_ID]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([[self::OUTSTANDING_CONTRACT_ID, $this->ownerEveCharacterId()]], $this->storedLinks());

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        self::assertSame([self::OUTSTANDING_CONTRACT_ID, 'waiting'], [$this->responseJson()['contract']['contractId'], $this->responseJson()['contract']['state']]);
    }

    public function testOwnerUnlinksTheContractAndTheLinesAreNotShippedAgain(): void
    {
        $this->linkContractDirectly(self::IN_TRANSIT_CONTRACT_ID);
        $this->actAs($this->owner);
        $this->request('DELETE', $this->linkUri());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedLinks());

        $this->actAs($this->owner);
        $this->request('GET', $this->courierUri());

        $body = $this->responseJson();
        self::assertNull($body['contract']);
        self::assertSame(
            [[self::TRITANIUM_TYPE_ID, 1_500, 'not_shipped'], [self::MORPHITE_TYPE_ID, 20, 'not_shipped']],
            $this->linesOf($body),
        );
    }

    public function testAcceptedAdminWhoIsNotTheOwnerReadsTheLinkedContractWithoutCandidates(): void
    {
        $this->linkContractDirectly(self::IN_TRANSIT_CONTRACT_ID);
        $this->actAs($this->member);
        $this->request('GET', $this->courierUri());

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(
            [false, [], self::IN_TRANSIT_CONTRACT_ID, 'in_transit', 7_500],
            [$body['canLink'], $body['candidates'], $body['contract']['contractId'], $body['contract']['state'], $body['reward']],
        );
    }

    public function testAcceptedAdminWhoIsNotTheOwnerIsDeniedLinkingAndUnlinking(): void
    {
        $this->linkContractDirectly(self::IN_TRANSIT_CONTRACT_ID);
        $this->actAs($this->member);

        $this->request('POST', $this->linkUri(), ['contractId' => self::OUTSTANDING_CONTRACT_ID]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can perform this action'], $this->errorDetail());

        $this->actAs($this->member);
        $this->request('DELETE', $this->linkUri());
        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'Only the project owner can perform this action'], $this->errorDetail());
        self::assertSame([[self::IN_TRANSIT_CONTRACT_ID, $this->ownerEveCharacterId()]], $this->storedLinks());
    }

    public function testLinkingAContractThatIsNotACourierContractIsRefusedAndKeepsNoLink(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->linkUri(), ['contractId' => self::ITEM_EXCHANGE_CONTRACT_ID]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->storedLinks());
    }

    public function testUnknownProjectIsNotFoundWhateverTheRights(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', '/api/industry/projects/'.Uuid::v4()->toRfc4122().'/courier');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function courierUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/courier';
    }

    private function linkUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/courier-contract';
    }

    private function ownerEveCharacterId(): int
    {
        return $this->owner->getMainCharacter()->getEveCharacterId();
    }

    private function linesOf(array $body): array
    {
        $lines = array_map(
            static fn (array $line): array => [$line['typeId'], $line['purchasedQuantity'], $line['transportState']],
            $body['lines'],
        );
        sort($lines);

        return $lines;
    }

    private function storedLinks(): array
    {
        $this->em->clear();
        $links = $this->em->getRepository(IndustryProjectCourierContract::class)->findBy(['project' => $this->ishtarBatch->getId()]);

        return array_map(
            static fn (IndustryProjectCourierContract $link): array => [$link->getContractId(), $link->getCharacterId()],
            $links,
        );
    }

    private function linkContractDirectly(int $contractId): void
    {
        $this->em->persist((new IndustryProjectCourierContract())
            ->setProject($this->ishtarBatch)
            ->setContractId($contractId)
            ->setCharacterId($this->ownerEveCharacterId()));
        $this->em->flush();
    }

    private function purchaseOn(IndustryProjectBomLine $line, int $quantity, float $totalPrice): void
    {
        $line->addPurchase((new IndustryProjectPurchase())
            ->setTypeId($line->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice($totalPrice / $quantity)
            ->setTotalPrice($totalPrice)
            ->setSource('manual'));
    }

    private function contract(int $contractId, string $type, string $status, string $issuedAt, float $volume, float $reward, float $collateral): array
    {
        return [
            'contract_id' => $contractId,
            'type' => $type,
            'status' => $status,
            'date_issued' => (new \DateTimeImmutable($issuedAt))->format('Y-m-d\TH:i:sP'),
            'volume' => $volume,
            'reward' => $reward,
            'collateral' => $collateral,
            'start_location_id' => self::JITA_STATION,
            'end_location_id' => self::WORKSHOP_STRUCTURE,
        ];
    }

    private function giveTheOwnerPilotAToken(): void
    {
        $this->owner->getMainCharacter()->setEveToken((new EveToken())
            ->setAccessToken('access-owner')
            ->setRefreshTokenEncrypted('refresh-owner')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['esi-contracts.read_character_contracts.v1']));
    }

    private function replaceEsi(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->esiClient->method('getPaginated')->willReturnCallback(function (string $endpoint): array {
            preg_match('#^/characters/(\d+)/contracts/#', $endpoint, $matches);

            return $this->contractsByEveCharacterId[(int) ($matches[1] ?? 0)] ?? [];
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        self::getContainer()->set(EsiClient::class, $this->esiClient);
        self::getContainer()->set(TokenManager::class, $tokenManager);
    }

    private function seedVolumesRouteAndFavoriteSystem(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Material');
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Mineral')->setCategory($category);
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        foreach ([$category, $group, $region, $constellation] as $staticRow) {
            $this->em->persist($staticRow);
        }
        $this->em->persist((new InvType())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setGroup($group)->setVolume(self::TRITANIUM_VOLUME));
        $this->em->persist((new InvType())->setTypeId(self::MORPHITE_TYPE_ID)->setTypeName('Morphite')->setGroup($group)->setVolume(self::MORPHITE_VOLUME));
        foreach ([self::JITA_SYSTEM_ID => 'Jita', self::PERIMETER_SYSTEM_ID => 'Perimeter', self::URLEN_SYSTEM_ID => 'Urlen'] as $solarSystemId => $name) {
            $this->em->persist((new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name)->setConstellation($constellation)->setRegionId(self::REGION_ID));
        }
        foreach ([[self::JITA_SYSTEM_ID, self::URLEN_SYSTEM_ID], [self::URLEN_SYSTEM_ID, self::PERIMETER_SYSTEM_ID]] as [$from, $to]) {
            $this->em->persist((new MapSolarSystemJump())
                ->setFromSolarSystemId($from)
                ->setToSolarSystemId($to)
                ->setFromRegionId(self::REGION_ID)
                ->setFromConstellationId(self::CONSTELLATION_ID)
                ->setToRegionId(self::REGION_ID)
                ->setToConstellationId(self::CONSTELLATION_ID));
        }
        $this->em->persist((new IndustryUserSettings())->setUser($this->owner)->setFavoriteManufacturingSystemId(self::PERIMETER_SYSTEM_ID));
    }
}
