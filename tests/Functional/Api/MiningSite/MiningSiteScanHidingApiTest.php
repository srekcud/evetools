<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
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

#[Group('issue-372')]
final class MiningSiteScanHidingApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const string FIRST_SCAN_AT = '2026-11-05T20:00:00+00:00';
    private const string SITE_NOT_FOUND_MESSAGE = 'Mining site not found';
    private const string SCAN_NOT_FOUND_MESSAGE = 'Mining site scan not found';
    private const string SCAN_HIDDEN_EVENT = 'site_scan_hidden';
    private const string UNKNOWN_SCAN_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f';

    private KernelBrowser $client;
    private ?string $actingUserId = null;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dax;
    private User $elia;
    private User $fenn;
    private MiningOperation $spodumain;
    private string $spodumainId;
    private string $siteId;
    private string $privateSiteId;

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
        $this->dax = $this->persistMiningPilot($this->em, 'Dax Orren', self::C1);
        $this->elia = $this->persistMiningPilot($this->em, 'Elia Varr', self::C1);
        $this->fenn = $this->persistMiningPilot($this->em, 'Fenn Orsay', self::C1);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Spodumain — 20:00', self::FIRST_SCAN_AT, ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Admin, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->addMiningMembership($this->spodumain, $this->dax, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Dax Orren']);
        $this->addMiningMembership($this->spodumain, $this->fenn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $site = $this->persistSite($this->aria, $this->spodumain, 'Belt Aldranette');
        $privateSite = $this->persistSite($this->aria, null, 'Anomalie privée');
        $this->em->flush();
        $this->spodumainId = (string) $this->spodumain->getId();
        $this->siteId = (string) $site->getId();
        $this->privateSiteId = (string) $privateSite->getId();
    }

    public function testAuthorHidesHisScanAndTheFeedRenumbersWhileTheRecordedEstimatesStayAsAnnounced(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->cyra);
        $this->request('GET', $this->scansUri($this->siteId));
        $before = $this->feedOf($this->responseJson());

        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));
        $deletion = [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
        $this->request('GET', $this->scansUri($this->siteId));
        $after = $this->feedOf($this->responseJson());

        self::assertSame(
            [[204, ''], [[1, '20:00:00', null], [2, '20:10:00', '21:00:00'], [3, '20:20:00', '20:50:33']], [[1, '20:00:00', null], [2, '20:20:00', '20:50:33']], 3],
            [$deletion, $before, $after, $this->storedScanCountOf($this->siteId)],
        );
    }

    public function testOperationOwnerAndAdministratorHideTheScanOfAnotherParticipant(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $statuses = [];

        foreach ([[$this->aria, $scanIds[1]], [$this->brann, $scanIds[2]]] as [$remover, $scanId]) {
            $this->as($remover);
            $this->request('DELETE', $this->scanUri($this->siteId, $scanId));
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }
        $this->request('GET', $this->scansUri($this->siteId));

        self::assertSame([[204, 204], [[1, '20:00:00', null]]], [$statuses, $this->feedOf($this->responseJson())]);
    }

    public function testMemberWhoIsNeitherAuthorNorAdministratorCannotHideTheScanOfAnotherParticipant(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->dax);

        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));
        $refusal = $this->client->getResponse()->getStatusCode();
        $this->request('GET', $this->scansUri($this->siteId));

        self::assertSame([403, 3], [$refusal, \count($this->feedOf($this->responseJson()))]);
    }

    public function testPendingMemberAndStrangerGetTheSameNotFoundAsAMissingSiteWhenTheyHideAScan(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $details = [];

        foreach ([$this->fenn, $this->elia] as $outsider) {
            $this->as($outsider);
            $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));
            $details[] = $this->errorDetail();
        }

        self::assertSame([[404, self::SITE_NOT_FOUND_MESSAGE], [404, self::SITE_NOT_FOUND_MESSAGE], 3], [...$details, $this->storedScanCountOf($this->siteId)]);
    }

    public function testOnlyTheOwnerHidesTheScanOfAPrivateSiteAndAnotherUserGetsNotFound(): void
    {
        $this->as($this->aria);
        $this->request('POST', $this->scansUri($this->privateSiteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);
        $scanId = (string) ($this->responseJson()['id'] ?? '');
        $this->publishedUpdates = [];

        $this->as($this->brann);
        $this->request('DELETE', $this->scanUri($this->privateSiteId, $scanId));
        $stranger = $this->errorDetail();
        $this->as($this->aria);
        $this->request('DELETE', $this->scanUri($this->privateSiteId, $scanId));
        $removal = $this->client->getResponse()->getStatusCode();
        $this->request('GET', $this->scansUri($this->privateSiteId));

        self::assertSame([[404, self::SITE_NOT_FOUND_MESSAGE], 204, [], []], [$stranger, $removal, $this->publishedUpdates, $this->feedOf($this->responseJson())]);
    }

    public function testHidingAnAlreadyHiddenOrUnknownScanGetsNotFound(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->cyra);
        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));
        $first = $this->client->getResponse()->getStatusCode();

        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));
        $again = $this->errorDetail();
        $this->request('GET', $this->scanUri($this->siteId, $scanIds[1]));
        $read = $this->errorDetail();
        $this->request('DELETE', $this->scanUri($this->siteId, self::UNKNOWN_SCAN_ID));
        $unknown = $this->errorDetail();
        $this->request('DELETE', $this->scanUri($this->siteId, 'not-a-uuid'));
        $malformed = $this->errorDetail();

        self::assertSame(
            [204, ...array_fill(0, 4, [404, self::SCAN_NOT_FOUND_MESSAGE])],
            [$first, $again, $read, $unknown, $malformed],
        );
    }

    public function testSiteReadsTheLastVisibleScanAfterTheLastOneIsHiddenAndTheStatusIsKept(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->cyra);
        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[2]));
        $this->request('GET', '/api/mining-sites/'.$this->siteId);
        $site = $this->responseJson();

        self::assertSame(
            [2, '20:10:00', 'Cyra Lune', '21:00:00', MiningSiteStatus::Active->value],
            [$site['lastScanNumber'] ?? null, $this->instant($site['lastScanAt'] ?? null), $site['lastScanAuthorName'] ?? null, $this->instant($site['trendEta'] ?? null), $site['status'] ?? null],
        );
    }

    public function testSiteWithAllItsScansHiddenReadsLikeASiteWithoutScan(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->aria);
        foreach ($scanIds as $scanId) {
            $this->request('DELETE', $this->scanUri($this->siteId, $scanId));
        }
        $this->request('GET', '/api/mining-sites/'.$this->siteId);
        $site = $this->responseJson();

        self::assertSame(
            [null, null, null, null],
            [$site['lastScanNumber'] ?? null, $site['lastScanAt'] ?? null, $site['firstScanAt'] ?? null, $site['trendEta'] ?? null],
        );
    }

    public function testHidingAScanIsPublishedToTheOwnerAndTheAcceptedMembersButNotToThePendingOnes(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->as($this->brann);
        $this->publishedUpdates = [];

        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));

        $event = [self::SCAN_HIDDEN_EVENT, $this->spodumainId, ['scanId', 'siteId'], $this->siteId, $scanIds[1]];
        self::assertSame(
            ['Aria Solberg' => [$event], 'Brann Holt' => [$event], 'Cyra Lune' => [$event], 'Dax Orren' => [$event], 'Fenn Orsay' => []],
            $this->eventsReceivedByEachPilot(),
        );
    }

    public function testAnonymousVisitorCannotHideAScan(): void
    {
        $scanIds = $this->recordThreeScansOfTheSharedSite();
        $this->actingUserId = null;
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $this->request('DELETE', $this->scanUri($this->siteId, $scanIds[1]));

        $status = $this->client->getResponse()->getStatusCode();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        self::assertSame([401, 3], [$status, $this->storedScanCountOf($this->siteId)]);
    }

    /**
     * @return list<string>
     */
    private function recordThreeScansOfTheSharedSite(): array
    {
        $scanIds = [];
        foreach ([[$this->aria, '20:00:00', 30_000], [$this->cyra, '20:10:00', 25_000], [$this->cyra, '20:20:00', 18_000]] as [$participant, $time, $units]) {
            self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
            $this->as($participant);
            $this->request('POST', $this->scansUri($this->siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => $units]]]);
            $scanIds[] = (string) ($this->responseJson()['id'] ?? '');
        }

        return $scanIds;
    }

    private function feedOf(array $scans): array
    {
        return array_map(
            fn (array $scan): array => [$scan['number'] ?? null, $this->instant($scan['scannedAt'] ?? null), $this->instant($scan['trendEta'] ?? null)],
            array_values(array_filter($scans, 'is_array')),
        );
    }

    private function eventsReceivedByEachPilot(): array
    {
        $pilotNamesByTopic = [];
        foreach ([$this->aria, $this->brann, $this->cyra, $this->dax, $this->fenn] as $pilot) {
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
            ];
        }
        ksort($received);

        return $received;
    }

    private function scansUri(string $siteId): string
    {
        return sprintf('/api/mining-sites/%s/scans', $siteId);
    }

    private function scanUri(string $siteId, string $scanId): string
    {
        return sprintf('/api/mining-sites/%s/scans/%s', $siteId, $scanId);
    }

    private function persistSite(User $owner, ?MiningOperation $operation, string $label): MiningSite
    {
        $site = (new MiningSite())
            ->setOwner($owner)
            ->setOperation($operation)
            ->setLabel($label)
            ->setSolarSystemId(self::ALDRANETTE)
            ->setCreatedAt(new \DateTimeImmutable('2026-11-05T18:00:00+00:00'));
        $this->em->persist($site);

        return $site;
    }

    private function storedScanCountOf(string $siteId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_site_scans WHERE site_id = ?', [$siteId]);
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

    private function instant(?string $iso): ?string
    {
        return $iso === null ? null : (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s');
    }
}
