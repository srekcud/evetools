<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
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

#[Group('issue-305')]
final class MiningSiteNextSiteApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const string NEXT_SITE_AT = '2026-11-05T20:30:00+00:00';
    private const string OPERATION_NOT_FOUND_MESSAGE = 'Mining operation not found';
    private const string UNKNOWN_OPERATION_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f';
    private const string SITE_NEXT_STARTED_EVENT = 'site_next_started';
    private const string NO_SITE_ID = '00000000-0000-0000-0000-000000000000';
    private const string EARLIER_CLOSURE_AT = '2026-11-05T19:00:00+00:00';

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
    private string $beltAldranetteId;
    private string $beltJaspetId;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NEXT_SITE_AT));
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
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie Spodumain — 20:00', '2026-11-05T20:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt']);
        $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra Lune']);
        $this->addMiningMembership($this->spodumain, $this->dorn, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, []);
        $beltAldranette = $this->persistSite($this->aria, $this->spodumain, 'Belt Aldranette', MiningSiteStatus::Active);
        $beltJaspet = $this->persistSite($this->aria, $this->spodumain, 'Belt Jaspet', MiningSiteStatus::Active);
        $this->em->flush();
        $this->spodumainId = (string) $this->spodumain->getId();
        $this->beltAldranetteId = (string) $beltAldranette->getId();
        $this->beltJaspetId = (string) $beltJaspet->getId();
    }

    public function testOwnerStartsTheNextSiteAndEveryActiveSiteOfTheOperationIsClosedAtThatInstant(): void
    {
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie Spodumain', 'solarSystemId' => self::ALDRANETTE]);

        $created = $this->responseJson();
        $createdId = (string) ($created['id'] ?? self::NO_SITE_ID);
        self::assertSame(
            [
                [201, 'Anomalie Spodumain', 'active', $this->spodumainId, self::ALDRANETTE],
                [[MiningSiteStatus::Closed->value, '20:30:00'], [MiningSiteStatus::Closed->value, '20:30:00']],
                [MiningSiteStatus::Active->value, null, $this->aria->getId()?->toRfc4122(), $this->spodumainId],
            ],
            [
                [$this->client->getResponse()->getStatusCode(), $created['label'] ?? null, $created['status'] ?? null, $created['operationId'] ?? null, $created['solarSystemId'] ?? null],
                [$this->storedClosureOf($this->beltAldranetteId), $this->storedClosureOf($this->beltJaspetId)],
                [$this->storedStatusOf($createdId), $this->storedClosedAtOf($createdId), $this->storedOwnerIdOf($createdId), $this->storedOperationIdOf($createdId)],
            ],
        );
    }

    public function testNextSiteIsPublishedOnceToTheOwnerAndTheAcceptedMembersWithTheNewSiteAndTheClosedSites(): void
    {
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie Spodumain']);

        $createdId = $this->responseJson()['id'] ?? null;
        $event = [self::SITE_NEXT_STARTED_EVENT, $this->spodumainId, ['closedSiteIds', 'siteId'], $createdId, $this->sortedIds([$this->beltAldranetteId, $this->beltJaspetId])];
        self::assertSame(
            ['Aria Solberg' => [$event], 'Brann Holt' => [$event], 'Cyra Lune' => [$event], 'Dorn Achar' => [], 'Elia Varr' => []],
            $this->eventsReceivedByEachPilot(),
        );
    }

    public function testParticipantWhoIsNeitherOwnerOfTheActiveSitesNorAdministratorIsRefusedAndNothingChanges(): void
    {
        $this->as($this->brann);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie Spodumain']);

        self::assertSame(
            [403, [[MiningSiteStatus::Active->value, null], [MiningSiteStatus::Active->value, null]], 2, []],
            [
                $this->client->getResponse()->getStatusCode(),
                [$this->storedClosureOf($this->beltAldranetteId), $this->storedClosureOf($this->beltJaspetId)],
                $this->storedSiteCountOf($this->spodumainId),
                $this->publishedUpdates,
            ],
        );
    }

    public function testParticipantOwningOneActiveSiteIsStillRefusedWhileAnotherActiveSiteBelongsToSomeoneElse(): void
    {
        $this->persistSite($this->brann, $this->spodumain, 'Belt Brann', MiningSiteStatus::Active);
        $this->em->flush();
        $this->as($this->brann);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie Spodumain']);

        self::assertSame(
            [403, 3, 3, []],
            [$this->client->getResponse()->getStatusCode(), $this->storedSiteCountOf($this->spodumainId), $this->storedActiveSiteCountOf($this->spodumainId), $this->publishedUpdates],
        );
    }

    public function testAdministratorOfTheOperationClosesTheSitesOfAnotherParticipantAndOwnsTheNewSite(): void
    {
        $this->spodumain->membershipOf($this->brann)?->setRole(MiningOperationMemberRole::Admin);
        $this->em->flush();
        $this->as($this->brann);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie Spodumain']);

        $createdId = (string) ($this->responseJson()['id'] ?? self::NO_SITE_ID);
        self::assertSame(
            [201, [MiningSiteStatus::Closed->value, MiningSiteStatus::Closed->value], MiningSiteStatus::Active->value, $this->brann->getId()?->toRfc4122()],
            [
                $this->client->getResponse()->getStatusCode(),
                [$this->storedStatusOf($this->beltAldranetteId), $this->storedStatusOf($this->beltJaspetId)],
                $this->storedStatusOf($createdId),
                $this->storedOwnerIdOf($createdId),
            ],
        );
    }

    public function testOperationWithoutActiveSiteJustGetsTheNewSiteAndKeepsTheClosureDateOfItsClosedSite(): void
    {
        $empty = $this->persistMiningOperation($this->em, $this->aria, 'Anomalie vide', '2026-11-05T19:00:00+00:00', ownerCharacterNames: ['Aria Solberg']);
        $closedSite = $this->persistSite($this->aria, $empty, 'Belt ancien', MiningSiteStatus::Closed);
        $closedSite->setClosedAt(new \DateTimeImmutable(self::EARLIER_CLOSURE_AT));
        $this->em->flush();
        $emptyId = (string) $empty->getId();
        $closedSiteId = (string) $closedSite->getId();
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($emptyId), ['label' => 'Belt Plex']);

        $createdId = (string) ($this->responseJson()['id'] ?? self::NO_SITE_ID);
        $event = [self::SITE_NEXT_STARTED_EVENT, $emptyId, ['closedSiteIds', 'siteId'], $createdId, []];
        self::assertSame(
            [201, 2, [MiningSiteStatus::Closed->value, '19:00:00'], MiningSiteStatus::Active->value, [$event]],
            [
                $this->client->getResponse()->getStatusCode(),
                $this->storedSiteCountOf($emptyId),
                $this->storedClosureOf($closedSiteId),
                $this->storedStatusOf($createdId),
                $this->eventsReceivedByEachPilot()['Aria Solberg'],
            ],
        );
    }

    public function testPendingMemberAndStrangerGetNotFoundAndNothingChanges(): void
    {
        $details = [];
        foreach ([$this->dorn, $this->elia] as $outsider) {
            $this->as($outsider);
            $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie intruse']);
            $details[] = $this->errorDetail();
        }

        self::assertSame(
            [[404, self::OPERATION_NOT_FOUND_MESSAGE], [404, self::OPERATION_NOT_FOUND_MESSAGE], 2, 2, []],
            [...$details, $this->storedSiteCountOf($this->spodumainId), $this->storedActiveSiteCountOf($this->spodumainId), $this->publishedUpdates],
        );
    }

    public function testMalformedOrUnknownOperationIdGetsNotFoundWithoutAServerError(): void
    {
        $this->as($this->aria);
        $details = [];
        foreach (['not-a-uuid', self::UNKNOWN_OPERATION_ID] as $operationId) {
            $this->request('POST', $this->nextSiteUri($operationId), ['label' => 'Belt perdu']);
            $details[] = $this->errorDetail();
        }

        self::assertSame(array_fill(0, 2, [404, self::OPERATION_NOT_FOUND_MESSAGE]), $details);
    }

    public function testArchivedOperationRefusesTheNextSiteAndKeepsItsSitesActive(): void
    {
        $archived = $this->persistMiningOperation($this->em, $this->aria, 'Ancienne anomalie', '2026-11-01T20:00:00+00:00', MiningOperationStatus::Completed, ownerCharacterNames: ['Aria Solberg']);
        $oldSite = $this->persistSite($this->aria, $archived, 'Belt ancien', MiningSiteStatus::Active);
        $this->em->flush();
        $archivedId = (string) $archived->getId();
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($archivedId), ['label' => 'Belt tardif']);

        self::assertSame(
            [400, 1, MiningSiteStatus::Active->value, []],
            [$this->client->getResponse()->getStatusCode(), $this->storedSiteCountOf($archivedId), $this->storedStatusOf((string) $oldSite->getId()), $this->publishedUpdates],
        );
    }

    public function testBlankLabelIsRefusedWithAValidationErrorAndNothingChanges(): void
    {
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => '   ']);

        self::assertSame(
            [422, 2, 2, []],
            [$this->client->getResponse()->getStatusCode(), $this->storedSiteCountOf($this->spodumainId), $this->storedActiveSiteCountOf($this->spodumainId), $this->publishedUpdates],
        );
    }

    public function testBodyWithoutLabelAndLabelOfMoreThanOneHundredCharactersAreRefusedWithAValidationError(): void
    {
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), []);
        $withoutLabel = $this->client->getResponse()->getStatusCode();
        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => str_repeat('a', 101)]);
        $tooLong = $this->client->getResponse()->getStatusCode();
        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => str_repeat('a', 100)]);
        $atTheLimit = $this->client->getResponse()->getStatusCode();

        self::assertSame([422, 422, 201], [$withoutLabel, $tooLong, $atTheLimit]);
    }

    public function testSolarSystemUnknownToTheSdeOrOutOfRangeIsRefusedWithAValidationErrorAndNothingChanges(): void
    {
        $this->as($this->aria);

        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Belt Plex', 'solarSystemId' => self::UNKNOWN_SOLAR_SYSTEM]);
        $unknown = $this->client->getResponse()->getStatusCode();
        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Belt Plex', 'solarSystemId' => 2_147_483_648]);
        $outOfRange = $this->client->getResponse()->getStatusCode();

        self::assertSame(
            [422, 422, 2, 2, []],
            [$unknown, $outOfRange, $this->storedSiteCountOf($this->spodumainId), $this->storedActiveSiteCountOf($this->spodumainId), $this->publishedUpdates],
        );
    }

    public function testAnonymousVisitorCannotStartTheNextSite(): void
    {
        $this->request('POST', $this->nextSiteUri($this->spodumainId), ['label' => 'Anomalie anonyme']);

        self::assertSame([401, 2], [$this->client->getResponse()->getStatusCode(), $this->storedSiteCountOf($this->spodumainId)]);
    }

    public function testSiteCreationLinkedToTheOperationStillLeavesTheActiveSitesOpen(): void
    {
        $this->as($this->brann);

        $this->request('POST', '/api/mining-sites', ['label' => 'Belt voisin', 'operationId' => $this->spodumainId]);

        self::assertSame(
            [201, 3, 3, [null, null], []],
            [
                $this->client->getResponse()->getStatusCode(),
                $this->storedSiteCountOf($this->spodumainId),
                $this->storedActiveSiteCountOf($this->spodumainId),
                [$this->storedClosedAtOf($this->beltAldranetteId), $this->storedClosedAtOf($this->beltJaspetId)],
                $this->publishedUpdates,
            ],
        );
    }

    private function nextSiteUri(string $operationId): string
    {
        return sprintf('/api/mining-operations/%s/next-site', $operationId);
    }

    private function persistSite(User $owner, MiningOperation $operation, string $label, MiningSiteStatus $status): MiningSite
    {
        $site = (new MiningSite())
            ->setOwner($owner)
            ->setOperation($operation)
            ->setLabel($label)
            ->setSolarSystemId(self::ALDRANETTE)
            ->setStatus($status)
            ->setCreatedAt(new \DateTimeImmutable('2026-11-05T18:00:00+00:00'));
        $this->em->persist($site);

        return $site;
    }

    private function storedStatusOf(string $siteId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM mining_sites WHERE id = ?', [$siteId]);
    }

    private function storedClosedAtOf(string $siteId): ?string
    {
        $closedAt = $this->em->getConnection()->fetchOne('SELECT closed_at FROM mining_sites WHERE id = ?', [$siteId]);

        return $closedAt === false || $closedAt === null ? null : (string) $closedAt;
    }

    private function storedClosureOf(string $siteId): array
    {
        return [$this->storedStatusOf($siteId), $this->timeOfDay($this->storedClosedAtOf($siteId))];
    }

    private function storedOwnerIdOf(string $siteId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT owner_id FROM mining_sites WHERE id = ?', [$siteId]);
    }

    private function storedOperationIdOf(string $siteId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT operation_id FROM mining_sites WHERE id = ?', [$siteId]);
    }

    private function storedSiteCountOf(string $operationId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_sites WHERE operation_id = ?', [$operationId]);
    }

    private function storedActiveSiteCountOf(string $operationId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_sites WHERE operation_id = ? AND status = ?', [$operationId, MiningSiteStatus::Active->value]);
    }

    private function sortedIds(array $ids): array
    {
        sort($ids);

        return $ids;
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
                $this->sortedIds($data['closedSiteIds'] ?? []),
            ];
        }
        ksort($received);

        return $received;
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

    private function timeOfDay(?string $instant): ?string
    {
        return $instant === null ? null : (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))->format('H:i:s');
    }
}
