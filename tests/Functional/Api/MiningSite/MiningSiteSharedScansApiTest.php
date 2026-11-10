<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSiteStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[Group('issue-306')]
final class MiningSiteSharedScansApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const string FIRST_SCAN_AT = '2026-11-05T20:00:00+00:00';
    private const string SITE_NOT_FOUND_MESSAGE = 'Mining site not found';
    private const string OPERATION_NOT_FOUND_MESSAGE = 'Mining operation not found';
    private const string UNKNOWN_OPERATION_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f';
    private const string SITE_SCANNED_EVENT = 'site_scan_recorded';

    private KernelBrowser $client;
    private ?string $actingUserId = null;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;
    private User $elia;
    private MiningOperation $spodumain;
    private string $spodumainId;
    private string $siteId;
    private string $ariaPrivateSiteId;
    private string $brannSiteId;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::FIRST_SCAN_AT));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->prepareContainer();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedMiningSiteSde($this->em);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C1);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', self::C1);
        $this->elia = $this->persistMiningPilot($this->em, 'Elia Varr', self::C1);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Spodumain — 20:00', self::FIRST_SCAN_AT, ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->addMiningMembership($this->spodumain, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $site = $this->persistSite($this->aria, $this->spodumain, 'Belt Aldranette', '2026-11-05T18:00:00+00:00');
        $ariaPrivateSite = $this->persistSite($this->aria, null, 'Anomalie privée', '2026-11-05T19:30:00+00:00');
        $brannSite = $this->persistSite($this->brann, $this->spodumain, 'Belt Jaspet', '2026-11-05T19:00:00+00:00');
        $this->persistSite($this->brann, null, 'Belt privé', '2026-11-05T19:45:00+00:00');
        $this->em->flush();
        $this->spodumainId = (string) $this->spodumain->getId();
        $this->siteId = (string) $site->getId();
        $this->ariaPrivateSiteId = (string) $ariaPrivateSite->getId();
        $this->brannSiteId = (string) $brannSite->getId();
    }

    public function testThreeParticipantsScanTheSharedSiteAndEachScanIsNumberedAndSignedByItsAuthor(): void
    {
        $responses = [];
        foreach ([[$this->aria, '20:00:00', 30_000], [$this->brann, '20:10:00', 25_000], [$this->cyra, '20:20:00', 18_000]] as [$participant, $time, $units]) {
            self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
            $this->as($participant);
            $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => $units]]]);
            $responses[] = ['status' => $this->client->getResponse()->getStatusCode(), 'body' => $this->responseJson()];
        }
        $this->as($this->aria);
        $this->request('GET', $this->scansUri($this->siteId));
        $thread = array_values(array_filter($this->responseJson(), 'is_array'));

        $third = $responses[2]['body'];
        self::assertSame(
            [
                [201, 201, 201],
                [1, 2, 3],
                ['Aria Solberg', 'Brann Holt', 'Cyra Lune'],
                [1, 1, 1],
                ['20:45:43', '20:50:33'],
                [[1, 'Aria Solberg', '20:00:00', null], [2, 'Brann Holt', '20:10:00', '21:00:00'], [3, 'Cyra Lune', '20:20:00', '20:50:33']],
                ['authorName', 'id', 'missingPriceTypeIds', 'number', 'recentEta', 'remainingValue', 'scannedAt', 'totalUnits', 'totalVolumeM3', 'trendEta', 'typeCount', 'types', 'valuationSource'],
            ],
            [
                array_column($responses, 'status'),
                array_map(static fn (array $response): ?int => $response['body']['number'] ?? null, $responses),
                array_map(static fn (array $response): ?string => $response['body']['authorName'] ?? null, $responses),
                array_map(static fn (array $response): ?int => $response['body']['typeCount'] ?? null, $responses),
                [$this->instant($third['recentEta'] ?? null), $this->instant($third['trendEta'] ?? null)],
                array_map(fn (array $scan): array => [$scan['number'] ?? null, $scan['authorName'] ?? null, $this->instant($scan['scannedAt'] ?? null), $this->instant($scan['trendEta'] ?? null)], $thread),
                $this->sortedKeysOfNonNullValues($third),
            ],
        );
    }

    public function testPendingMemberAndStrangerGetTheSameNotFoundAsAMissingSiteOnReadScansAndClose(): void
    {
        $details = [];

        foreach ([$this->dorn, $this->elia] as $outsider) {
            $this->as($outsider);
            foreach ([
                ['GET', '/api/mining-sites/'.$this->siteId, null],
                ['GET', $this->scansUri($this->siteId), null],
                ['POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]],
                ['POST', sprintf('/api/mining-sites/%s/close', $this->siteId), []],
            ] as [$method, $uri, $body]) {
                $this->request($method, $uri, $body);
                $details[] = $this->errorDetail();
            }
        }

        self::assertSame(array_fill(0, 8, [404, self::SITE_NOT_FOUND_MESSAGE]), $details);
        self::assertSame([MiningSiteStatus::Active->value, 0], [$this->storedStatusOf($this->siteId), $this->storedScanCountOf($this->siteId)]);
    }

    public function testParticipantWhoLeavesTheOperationLosesTheAccessToItsSitesOnTheNextRequest(): void
    {
        $this->as($this->cyra);
        $this->request('GET', '/api/mining-sites/'.$this->siteId);
        $whileAccepted = $this->client->getResponse()->getStatusCode();
        $this->removeMembershipOf($this->cyra);

        $this->request('GET', '/api/mining-sites/'.$this->siteId);
        $afterLeaving = $this->errorDetail();
        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);

        self::assertSame(
            [200, [404, self::SITE_NOT_FOUND_MESSAGE], 404],
            [$whileAccepted, $afterLeaving, $this->client->getResponse()->getStatusCode()],
        );
    }

    public function testOwnerOfASiteLinkedToTheOperationWhoLeavesItCannotScanNorReadItAnymore(): void
    {
        $cyraSite = $this->persistSite($this->cyra, $this->spodumain, 'Cyra belt', '2026-11-04 19:00:00');
        $cyraSiteId = $cyraSite->getId()->toRfc4122();
        $this->removeMembershipOf($this->cyra);

        $this->as($this->cyra);
        $this->request('GET', '/api/mining-sites/'.$cyraSiteId);
        $read = $this->errorDetail();
        $this->request('POST', $this->scansUri($cyraSiteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);

        self::assertSame(
            [[404, self::SITE_NOT_FOUND_MESSAGE], 404, 0],
            [$read, $this->client->getResponse()->getStatusCode(), $this->storedScanCountOf($cyraSiteId)],
        );
    }

    public function testParticipantCannotScanASiteTheOwnerClosed(): void
    {
        $this->as($this->aria);
        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->siteId), []);
        $closing = $this->client->getResponse()->getStatusCode();

        $this->as($this->brann);
        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 25_000]]]);

        self::assertSame([200, 400, 0], [$closing, $this->client->getResponse()->getStatusCode(), $this->storedScanCountOf($this->siteId)]);
    }

    public function testMemberWhoIsNeitherOwnerNorAdministratorCannotCloseTheSiteOfAnotherParticipant(): void
    {
        $this->as($this->brann);

        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->siteId), []);

        self::assertSame([403, MiningSiteStatus::Active->value], [$this->client->getResponse()->getStatusCode(), $this->storedStatusOf($this->siteId)]);
    }

    public function testAdministratorOfTheOperationClosesTheSiteOfAnotherParticipant(): void
    {
        $this->spodumain->membershipOf($this->brann)?->setRole(MiningOperationMemberRole::Admin);
        $this->em->flush();
        $this->as($this->brann);

        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->siteId), []);

        self::assertSame([200, MiningSiteStatus::Closed->value, MiningSiteStatus::Closed->value], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['status'] ?? null, $this->storedStatusOf($this->siteId)]);
    }

    public function testOwnerOfAnOperationSiteWhoIsNotAdministratorClosesHisOwnSite(): void
    {
        $this->as($this->brann);

        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->brannSiteId), []);

        self::assertSame([200, MiningSiteStatus::Closed->value], [$this->client->getResponse()->getStatusCode(), $this->responseJson()['status'] ?? null]);
    }

    public function testScanOfAParticipantIsPublishedToTheOwnerAndTheAcceptedMembersWithoutUnitsNorPrices(): void
    {
        $this->as($this->aria);
        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);
        $this->publishedUpdates = [];
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:10:00+00:00'));
        $this->as($this->brann);

        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 25_000]]]);

        $scanId = $this->responseJson()['id'] ?? null;
        $event = [self::SITE_SCANNED_EVENT, $this->spodumainId, ['authorName', 'number', 'recentEta', 'scanId', 'scannedAt', 'siteId', 'trendEta'], $this->siteId, $scanId, 2, 'Brann Holt', '20:10:00', '21:00:00', '21:00:00'];
        self::assertSame(
            ['Aria Solberg' => [$event], 'Brann Holt' => [$event], 'Cyra Lune' => [$event], 'Dorn Achar' => [], 'Elia Varr' => []],
            $this->eventsReceivedByEachPilot(),
        );
    }

    public function testScanOnAPrivateSiteIsNotPublishedAndAnotherParticipantGetsNotFoundOnIt(): void
    {
        $this->as($this->aria);
        $this->request('POST', $this->scansUri($this->ariaPrivateSiteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);
        $recorded = $this->client->getResponse()->getStatusCode();

        $this->as($this->brann);
        $this->request('GET', '/api/mining-sites/'.$this->ariaPrivateSiteId);

        self::assertSame(
            [201, [], [404, self::SITE_NOT_FOUND_MESSAGE]],
            [$recorded, $this->publishedUpdates, $this->errorDetail()],
        );
    }

    public function testParticipantCreatesASiteLinkedToTheOperationAndTheOperationOwnerReadsIt(): void
    {
        $this->as($this->brann);
        $this->request('POST', '/api/mining-sites', ['label' => 'Belt Aldranette 2', 'solarSystemId' => self::ALDRANETTE, 'operationId' => $this->spodumainId]);
        $created = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];
        $createdId = (string) ($created[1]['id'] ?? '');

        $this->as($this->aria);
        $this->request('GET', '/api/mining-sites/'.$createdId);

        self::assertSame(
            [[201, 'Belt Aldranette 2', $this->spodumainId, $this->spodumainId], [200, 'Belt Aldranette 2', $this->spodumainId]],
            [
                [$created[0], $created[1]['label'] ?? null, $created[1]['operationId'] ?? null, $this->storedOperationIdOf($createdId)],
                [$this->client->getResponse()->getStatusCode(), $this->responseJson()['label'] ?? null, $this->responseJson()['operationId'] ?? null],
            ],
        );
    }

    public function testParticipantCannotCreateASiteInAnArchivedOperation(): void
    {
        $archived = $this->persistMiningOperation($this->em, $this->aria, 'Ancienne anomalie', '2026-11-01T20:00:00+00:00', MiningOperationStatus::Completed, ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($archived, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->em->flush();
        $this->as($this->brann);

        $this->request('POST', '/api/mining-sites', ['label' => 'Belt tardif', 'operationId' => (string) $archived->getId()]);

        self::assertSame([400, 0], [$this->client->getResponse()->getStatusCode(), $this->storedSiteCountLabelled('Belt tardif')]);
    }

    public function testPendingMemberAndStrangerCannotCreateASiteInTheOperationAndGetNotFound(): void
    {
        $details = [];
        foreach ([$this->dorn, $this->elia] as $outsider) {
            $this->as($outsider);
            $this->request('POST', '/api/mining-sites', ['label' => 'Belt intrus', 'operationId' => $this->spodumainId]);
            $details[] = $this->errorDetail();
        }

        self::assertSame(
            [[404, self::OPERATION_NOT_FOUND_MESSAGE], [404, self::OPERATION_NOT_FOUND_MESSAGE], 0],
            [...$details, $this->storedSiteCountLabelled('Belt intrus')],
        );
    }

    public function testMalformedOrUnknownOperationIdIsRefusedWithoutAServerError(): void
    {
        $this->as($this->brann);
        $refusals = [];

        foreach ([
            ['POST', '/api/mining-sites', ['label' => 'Belt cassé', 'operationId' => 'not-a-uuid']],
            ['POST', '/api/mining-sites', ['label' => 'Belt cassé', 'operationId' => self::UNKNOWN_OPERATION_ID]],
            ['GET', '/api/mining-sites?operationId=not-a-uuid', null],
            ['GET', '/api/mining-sites?operationId='.self::UNKNOWN_OPERATION_ID, null],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);
            $refusals[] = $this->errorDetail();
        }
        $this->request('POST', '/api/mining-sites', ['label' => 'Belt cassé', 'operationId' => 42]);
        $wrongType = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/mining-sites', ['label' => 'Belt cassé', 'operationId' => null]);
        $explicitlyPrivate = [$this->client->getResponse()->getStatusCode(), array_key_exists('operationId', $this->responseJson())];

        self::assertSame(
            [array_fill(0, 4, [404, self::OPERATION_NOT_FOUND_MESSAGE]), 400, [201, false], 1],
            [$refusals, $wrongType, $explicitlyPrivate, $this->storedSiteCountLabelled('Belt cassé')],
        );
    }

    public function testParticipantListsTheSitesOfTheOperationOfEveryOwnerNewestFirstAndOthersGetNotFound(): void
    {
        $this->as($this->brann);
        $this->request('GET', '/api/mining-sites?operationId='.$this->spodumainId);
        $ofTheOperation = [$this->client->getResponse()->getStatusCode(), array_column($this->responseJson(), 'label')];
        $this->request('GET', '/api/mining-sites');
        $ofTheOwner = array_column($this->responseJson(), 'label');
        $outsiders = [];
        foreach ([$this->dorn, $this->elia] as $outsider) {
            $this->as($outsider);
            $this->request('GET', '/api/mining-sites?operationId='.$this->spodumainId);
            $outsiders[] = $this->errorDetail();
        }

        self::assertSame(
            [[200, ['Belt Jaspet', 'Belt Aldranette']], ['Belt privé', 'Belt Jaspet'], [[404, self::OPERATION_NOT_FOUND_MESSAGE], [404, self::OPERATION_NOT_FOUND_MESSAGE]]],
            [$ofTheOperation, $ofTheOwner, $outsiders],
        );
    }

    public function testSiteExposesTheNumberAndTheAuthorNameOfItsLastScanWhateverItsAuthor(): void
    {
        $site = $this->em->find(MiningSite::class, $this->siteId);
        \assert($site instanceof MiningSite);
        $this->persistScan($site, '2026-11-05T20:00:00+00:00', 30_000, $this->aria);
        $this->persistScan($site, '2026-11-05T20:10:00+00:00', 25_000, $this->brann);
        $this->persistScan($site, '2026-11-05T20:20:00+00:00', 18_000, $this->cyra);
        $this->em->flush();

        $this->as($this->brann);
        $this->request('GET', '/api/mining-sites/'.$this->siteId);
        $withScans = $this->responseJson();
        $this->as($this->aria);
        $this->request('GET', '/api/mining-sites/'.$this->ariaPrivateSiteId);
        $withoutScan = $this->responseJson();

        self::assertSame(
            [[3, 'Cyra Lune', '20:20:00'], [null, null, null]],
            [
                [$withScans['lastScanNumber'] ?? null, $withScans['lastScanAuthorName'] ?? null, $this->instant($withScans['lastScanAt'] ?? null)],
                [$withoutScan['lastScanNumber'] ?? null, $withoutScan['lastScanAuthorName'] ?? null, $this->instant($withoutScan['lastScanAt'] ?? null)],
            ],
        );
    }

    public function testScanOfAnAuthorWithoutMainCharacterHasAnUnknownAuthorNameInsteadOfAServerError(): void
    {
        $fenn = $this->persistMiningPilot($this->em, 'Fenn Orsay', null);
        $this->addMiningMembership($this->spodumain, $fenn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Fenn Orsay']);
        $this->em->flush();
        $this->as($fenn);

        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);
        $recorded = [$this->client->getResponse()->getStatusCode(), $this->responseJson()['number'] ?? null, $this->responseJson()['authorName'] ?? null];
        $this->as($this->aria);
        $this->request('GET', '/api/mining-sites/'.$this->siteId);

        self::assertSame(
            [[201, 1, null], [1, null]],
            [$recorded, [$this->responseJson()['lastScanNumber'] ?? null, $this->responseJson()['lastScanAuthorName'] ?? null]],
        );
    }

    public function testAnonymousVisitorCannotListTheSitesOfAnOperationNorScanOneOfItsSites(): void
    {
        $this->request('GET', '/api/mining-sites?operationId='.$this->spodumainId);
        $listing = $this->client->getResponse()->getStatusCode();
        $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);

        self::assertSame([401, 401], [$listing, $this->client->getResponse()->getStatusCode()]);
    }

    private function removeMembershipOf(User $participant): void
    {
        $operation = $this->em->find(MiningOperation::class, $this->spodumainId);
        $member = $this->em->find(User::class, $participant->getId());
        \assert($operation instanceof MiningOperation && $member instanceof User);
        $operation->membershipOf($member)?->remove();
        $this->em->flush();
    }

    private function eventsReceivedByEachPilot(): array
    {
        $pilotNamesByTopic = [];
        foreach ([$this->aria, $this->brann, $this->cyra, $this->dorn, $this->elia] as $pilot) {
            $pilotNamesByTopic[sprintf('/user/%s/mining-operations', $pilot->getId())] = $pilot->getMainCharacter()?->getName();
        }

        $received = array_fill_keys(array_values($pilotNamesByTopic), []);
        foreach ($this->publishedUpdates as $update) {
            $decoded = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            $data = $decoded['data'];
            $keys = array_keys($data);
            sort($keys);
            $received[$pilotNamesByTopic[$update->getTopics()[0]] ?? 'unexpected topic '.$update->getTopics()[0]][] = [
                $decoded['action'],
                $decoded['operationId'],
                $keys,
                $data['siteId'] ?? null,
                $data['scanId'] ?? null,
                $data['number'] ?? null,
                $data['authorName'] ?? null,
                $this->instant($data['scannedAt'] ?? null),
                $this->instant($data['recentEta'] ?? null),
                $this->instant($data['trendEta'] ?? null),
            ];
        }
        ksort($received);

        return $received;
    }

    private function scansUri(string $siteId): string
    {
        return sprintf('/api/mining-sites/%s/scans', $siteId);
    }

    private function persistSite(User $owner, ?MiningOperation $operation, string $label, string $createdAt): MiningSite
    {
        $site = (new MiningSite())
            ->setOwner($owner)
            ->setOperation($operation)
            ->setLabel($label)
            ->setSolarSystemId(self::ALDRANETTE)
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->persist($site);

        return $site;
    }

    private function persistScan(MiningSite $site, string $scannedAt, int $units, User $author): void
    {
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setAuthor($author)
            ->setScannedAt(new \DateTimeImmutable($scannedAt))
            ->setTotalUnits($units)
            ->setTotalVolumeM3($units * self::VELDSPAR_VOLUME)
            ->setRemainingValue($units * self::VELDSPAR_UNIT_PRICE));
    }

    private function storedStatusOf(string $siteId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM mining_sites WHERE id = ?', [$siteId]);
    }

    private function storedScanCountOf(string $siteId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_site_scans WHERE site_id = ?', [$siteId]);
    }

    private function storedSiteCountLabelled(string $label): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_sites WHERE label = ?', [$label]);
    }

    private function storedOperationIdOf(string $siteId): ?string
    {
        $operationId = $this->em->getConnection()->fetchOne('SELECT operation_id FROM mining_sites WHERE id = ?', [$siteId]);

        return $operationId === false || $operationId === null ? null : (string) $operationId;
    }

    private function prepareContainer(): void
    {
        $this->replaceJitaBuyMarket();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    private function as(User $user): void
    {
        $this->actingUserId = (string) $user->getId();
    }

    private function freshClientLoggedInAs(string $userId): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->prepareContainer();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        if ($this->actingUserId !== null) {
            $this->freshClientLoggedInAs($this->actingUserId);
        }
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        $decoded = $content === '' ? [] : json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    private function sortedKeysOfNonNullValues(array $resource): array
    {
        $keys = array_keys(array_filter($resource, static fn (mixed $value): bool => $value !== null));
        sort($keys);

        return $keys;
    }

    private function instant(?string $iso): ?string
    {
        return $iso === null ? null : (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s');
    }
}
