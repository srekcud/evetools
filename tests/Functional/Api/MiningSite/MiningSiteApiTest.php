<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-267')]
final class MiningSiteApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SeedsMiningSiteMarket;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string UNKNOWN_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f';
    private const string SITE_NOT_FOUND_MESSAGE = 'Mining site not found';

    private KernelBrowser $client;
    private ?string $actingUserId = null;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private string $ariaBeltId;
    private string $ariaAnomalyId;
    private string $brannBeltId;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:00:00+00:00'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceJitaBuyMarket();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedMiningSiteSde($this->em);
        $this->aria = $this->persistUser();
        $this->brann = $this->persistUser();
        $ariaBelt = $this->persistSite($this->aria, 'Belt Aldranette', self::ALDRANETTE, '2026-11-03T18:00:00+00:00');
        $ariaAnomaly = $this->persistSite($this->aria, 'Anomalie Spodumain', null, '2026-11-05T18:00:00+00:00');
        $brannBelt = $this->persistSite($this->brann, 'Belt Jaspet', self::ALDRANETTE, '2026-11-04T18:00:00+00:00');
        $this->persistScan($ariaBelt, '2026-11-03T20:00:00+00:00', 30_000);
        $this->persistScan($ariaBelt, '2026-11-03T20:20:00+00:00', 18_000);
        $this->persistScan($brannBelt, '2026-11-04T20:00:00+00:00', 45_000);
        $this->em->flush();
        $this->ariaBeltId = (string) $ariaBelt->getId();
        $this->ariaAnomalyId = (string) $ariaAnomaly->getId();
        $this->brannBeltId = (string) $brannBelt->getId();
    }

    public function testOwnerCreatesAnActiveSiteWithOrWithoutASolarSystem(): void
    {
        $this->as($this->aria);

        $this->request('POST', '/api/mining-sites', ['label' => 'Belt Aldranette 2', 'solarSystemId' => self::ALDRANETTE]);
        $withSystem = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];
        $this->request('POST', '/api/mining-sites', ['label' => 'Anomalie Spodumain 2']);
        $withoutSystem = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];

        self::assertSame(
            [[201, 1, 'Belt Aldranette 2', self::ALDRANETTE, 'active'], [201, 1, 'Anomalie Spodumain 2', false, 'active']],
            [
                [$withSystem[0], preg_match(self::UUID_PATTERN, $withSystem[1]['id'] ?? ''), $withSystem[1]['label'] ?? null, $withSystem[1]['solarSystemId'] ?? null, $withSystem[1]['status'] ?? null],
                [$withoutSystem[0], preg_match(self::UUID_PATTERN, $withoutSystem[1]['id'] ?? ''), $withoutSystem[1]['label'] ?? null, array_key_exists('solarSystemId', $withoutSystem[1]), $withoutSystem[1]['status'] ?? null],
            ],
        );
    }

    #[Group('issue-366')]
    public function testSiteExposesTheNameOfItsSolarSystemOnCreationAndReadAndNullWithoutSystem(): void
    {
        $this->as($this->aria);

        $this->request('POST', '/api/mining-sites', ['label' => 'Belt Aldranette 2', 'solarSystemId' => self::ALDRANETTE]);
        $createdWithSystem = $this->responseJson();
        $this->request('POST', '/api/mining-sites', ['label' => 'Anomalie Spodumain 2']);
        $createdWithoutSystem = $this->responseJson();
        $this->request('GET', sprintf('/api/mining-sites/%s', $this->ariaBeltId));
        $readWithSystem = $this->responseJson();
        $this->request('GET', sprintf('/api/mining-sites/%s', $this->ariaAnomalyId));
        $readWithoutSystem = $this->responseJson();
        $this->request('GET', '/api/mining-sites');
        $listed = array_column($this->responseJson(), 'solarSystemName', 'label');

        self::assertSame(
            ['Aldranette', null, 'Aldranette', null, ['Belt Aldranette 2' => 'Aldranette', 'Belt Aldranette' => 'Aldranette']],
            [
                $createdWithSystem['solarSystemName'] ?? null,
                $createdWithoutSystem['solarSystemName'] ?? null,
                $readWithSystem['solarSystemName'] ?? null,
                $readWithoutSystem['solarSystemName'] ?? null,
                $listed,
            ],
        );
    }

    public function testOwnerListsOnlyHisSitesNewestFirstWithTheHistorySummaryAndFiltersBySolarSystem(): void
    {
        $this->as($this->aria);

        $this->request('GET', '/api/mining-sites');
        $all = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];
        $this->request('GET', '/api/mining-sites?solarSystemId='.self::ALDRANETTE);
        $filtered = $this->responseJson();

        self::assertSame(
            [
                200,
                ['Anomalie Spodumain', 'Belt Aldranette'],
                ['Belt Aldranette'],
                ['Belt Aldranette', self::ALDRANETTE, 'active', '20:00:00', '20:20:00', 1_200, 3_000.0, 450_000.0, 600.0, 270_000.0],
            ],
            [
                $all[0],
                array_column($all[1], 'label'),
                array_column($filtered, 'label'),
                $this->historyLineOf($all[1][1] ?? []),
            ],
        );
    }

    public function testOwnerRecordsThreeScansAndEachResponseAnnouncesTheEtasOfThatScanWithoutAnyFreeText(): void
    {
        $this->as($this->aria);
        $siteId = $this->createSiteOfAria();

        $responses = $this->recordVeldsparScans($siteId);

        $third = $responses[2]['body'];
        $veldspar = $third['types'][0] ?? [];
        self::assertSame(
            [
                [201, 201, 201],
                ['20:00:00', '20:10:00', '20:20:00'],
                [18_000, 1_800.0, 270_000.0, [], '20:45:43', '20:50:33'],
                ['maxDeviationPercent', 'maxDeviationUnits', 'recentEta', 'recentRate', 'remainingValue', 'status', 'trendEta', 'trendRate', 'typeId', 'unitPrice', 'units', 'volumeM3'],
                [self::VELDSPAR, 'present', 18_000, 700.0, '20:45:43', 600.0, '20:50:33', 666.67, 2.22],
                ['id', 'missingPriceTypeIds', 'number', 'recentEta', 'remainingValue', 'scannedAt', 'totalUnits', 'totalVolumeM3', 'trendEta', 'typeCount', 'types'],
            ],
            [
                array_column($responses, 'status'),
                array_map(fn (array $response): ?string => $this->instant($response['body']['scannedAt'] ?? null), $responses),
                [$third['totalUnits'] ?? null, $this->rounded($third['totalVolumeM3'] ?? null), $this->rounded($third['remainingValue'] ?? null), $third['missingPriceTypeIds'] ?? null, $this->instant($third['recentEta'] ?? null), $this->instant($third['trendEta'] ?? null)],
                $this->sortedKeysOfNonNullValues($veldspar),
                [$veldspar['typeId'] ?? null, $veldspar['status'] ?? null, $veldspar['units'] ?? null, $this->rounded($veldspar['recentRate'] ?? null), $this->instant($veldspar['recentEta'] ?? null), $this->rounded($veldspar['trendRate'] ?? null), $this->instant($veldspar['trendEta'] ?? null), $this->rounded($veldspar['maxDeviationUnits'] ?? null), $this->rounded($veldspar['maxDeviationPercent'] ?? null)],
                $this->sortedKeysOfNonNullValues($third),
            ],
        );
    }

    public function testOwnerReadsTheScansChronologicallyAScanByItsIdAndTheSiteWithTheValuesOfItsLastScan(): void
    {
        $this->as($this->aria);
        $siteId = $this->createSiteOfAria();
        $responses = $this->recordVeldsparScans($siteId);
        $secondScanId = $responses[1]['body']['id'] ?? '';

        $this->request('GET', sprintf('/api/mining-sites/%s/scans', $siteId));
        $scans = array_values(array_filter($this->responseJson(), 'is_array'));
        $this->request('GET', sprintf('/api/mining-sites/%s/scans/%s', $siteId, $secondScanId));
        $secondScan = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];
        $this->request('GET', sprintf('/api/mining-sites/%s', $siteId));
        $site = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];

        self::assertSame(
            [
                [['20:00:00', 30_000, null, null], ['20:10:00', 25_000, '21:00:00', '21:00:00'], ['20:20:00', 18_000, '20:45:43', '20:50:33']],
                [200, '20:10:00', 25_000, 500.0, '21:00:00'],
                [200, 'Belt Aldranette 2', self::ALDRANETTE, 'active', '20:00:00', '20:20:00', 1_200, 3_000.0, 450_000.0, 600.0, 270_000.0, '20:45:43', '20:50:33'],
            ],
            [
                array_map(fn (array $scan): array => [$this->instant($scan['scannedAt'] ?? null), $scan['totalUnits'] ?? null, $this->instant($scan['recentEta'] ?? null), $this->instant($scan['trendEta'] ?? null)], $scans),
                [$secondScan[0], $this->instant($secondScan[1]['scannedAt'] ?? null), $secondScan[1]['totalUnits'] ?? null, $this->rounded($secondScan[1]['types'][0]['recentRate'] ?? null), $this->instant($secondScan[1]['types'][0]['recentEta'] ?? null)],
                [$site[0], ...$this->historyLineOf($site[1]), $this->instant($site[1]['recentEta'] ?? null), $this->instant($site[1]['trendEta'] ?? null)],
            ],
        );
    }

    public function testOwnerClosesASiteByHandAndItRefusesFurtherScans(): void
    {
        $this->as($this->aria);

        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->ariaAnomalyId), []);
        $closed = [$this->client->getResponse()->getStatusCode(), $this->responseJson()['status'] ?? null];
        $this->request('POST', sprintf('/api/mining-sites/%s/scans', $this->ariaAnomalyId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]);

        self::assertSame([[200, 'closed'], 400], [$closed, $this->client->getResponse()->getStatusCode()]);
    }

    #[Group('issue-305')]
    public function testSiteExposesItsScanCountAndTheValueExtractedBetweenItsFirstAndLastScansAndLeavesItOutWithFewerThanTwoScans(): void
    {
        $this->as($this->aria);
        $this->request('GET', '/api/mining-sites');
        $ariaSites = array_column($this->responseJson(), null, 'label');
        $this->as($this->brann);
        $this->request('GET', '/api/mining-sites');
        $brannSites = array_column($this->responseJson(), null, 'label');

        $twoScans = $ariaSites['Belt Aldranette'] ?? [];
        $noScan = $ariaSites['Anomalie Spodumain'] ?? [];
        $oneScan = $brannSites['Belt Jaspet'] ?? [];
        self::assertSame(
            [[2, 180_000.0, false], [0, false, false], [1, false, false]],
            [
                [$twoScans['scanCount'] ?? null, $this->rounded($twoScans['extractedValue'] ?? null), array_key_exists('closedAt', $twoScans)],
                [$noScan['scanCount'] ?? null, array_key_exists('extractedValue', $noScan), array_key_exists('closedAt', $noScan)],
                [$oneScan['scanCount'] ?? null, array_key_exists('extractedValue', $oneScan), array_key_exists('closedAt', $oneScan)],
            ],
        );
    }

    #[Group('issue-305')]
    public function testManualClosureDatesTheSiteWithTheClockAndASecondClosureKeepsTheFirstDate(): void
    {
        $this->as($this->aria);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:30:00+00:00'));
        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->ariaAnomalyId), []);
        $firstClosure = [$this->client->getResponse()->getStatusCode(), $this->instant($this->responseJson()['closedAt'] ?? null)];
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:45:00+00:00'));

        $this->request('POST', sprintf('/api/mining-sites/%s/close', $this->ariaAnomalyId), []);

        self::assertSame(
            [[200, '20:30:00'], [200, '20:30:00'], '20:30:00'],
            [$firstClosure, [$this->client->getResponse()->getStatusCode(), $this->instant($this->responseJson()['closedAt'] ?? null)], $this->instant($this->storedClosedAtOf($this->ariaAnomalyId))],
        );
    }

    public function testInvalidInputIsRefusedWithAValidationError(): void
    {
        $this->as($this->aria);
        $scanUri = sprintf('/api/mining-sites/%s/scans', $this->ariaBeltId);
        $statuses = [];

        foreach ([
            ['/api/mining-sites', ['label' => '']],
            ['/api/mining-sites', ['label' => 'Belt fantôme', 'solarSystemId' => self::UNKNOWN_SOLAR_SYSTEM]],
            [$scanUri, ['lines' => [['typeId' => self::VELDSPAR, 'units' => 0]]]],
            [$scanUri, ['lines' => [['typeId' => self::VELDSPAR, 'units' => -5]]]],
            [$scanUri, ['lines' => [['typeId' => self::VELDSPAR, 'units' => 100], ['typeId' => self::VELDSPAR, 'units' => 200]]]],
            [$scanUri, ['lines' => [['typeId' => self::UNKNOWN_TYPE, 'units' => 100]]]],
        ] as [$uri, $body]) {
            $this->request('POST', $uri, $body);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame([422, 422, 422, 422, 422, 422], $statuses);
    }

    public function testOutOfRangeInputIsRefusedWithAValidationErrorInsteadOfAServerError(): void
    {
        $this->as($this->aria);
        $scanUri = sprintf('/api/mining-sites/%s/scans', $this->ariaBeltId);
        $tooManyLines = array_map(static fn (int $offset): array => ['typeId' => self::VELDSPAR + $offset, 'units' => 100], range(0, 100));
        $statuses = [];

        foreach ([
            ['/api/mining-sites', ['label' => 'Belt lointaine', 'solarSystemId' => 2_147_483_648]],
            [$scanUri, ['lines' => [['typeId' => self::VELDSPAR, 'units' => 2_147_483_648]]]],
            [$scanUri, ['lines' => [['typeId' => 2_147_483_648, 'units' => 100]]]],
            [$scanUri, ['lines' => $tooManyLines]],
        ] as [$uri, $body]) {
            $this->request('POST', $uri, $body);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame([422, 422, 422, 422], $statuses);
    }

    public function testOtherUserGetsTheSameNotFoundAsAMissingSiteOnReadScansAndClose(): void
    {
        $this->as($this->brann);
        $foreignScanUri = sprintf('/api/mining-sites/%s/scans', $this->ariaBeltId);
        $details = [];

        foreach ([
            ['GET', '/api/mining-sites/'.$this->ariaBeltId, null],
            ['GET', $foreignScanUri, null],
            ['POST', $foreignScanUri, ['lines' => [['typeId' => self::VELDSPAR, 'units' => 30_000]]]],
            ['POST', sprintf('/api/mining-sites/%s/close', $this->ariaBeltId), []],
            ['GET', '/api/mining-sites/'.self::UNKNOWN_SITE_ID, null],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);
            $details[] = $this->errorDetail();
        }

        self::assertSame(array_fill(0, 5, [404, self::SITE_NOT_FOUND_MESSAGE]), $details);
        self::assertSame(MiningSiteStatus::Active->value, (string) $this->em->getConnection()->fetchOne('SELECT status FROM mining_sites WHERE id = ?', [$this->ariaBeltId]));
    }

    public function testAnonymousVisitorCannotListNorCreateSites(): void
    {
        $this->request('GET', '/api/mining-sites');
        $listing = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/mining-sites', ['label' => 'Belt anonyme']);

        self::assertSame([401, 401], [$listing, $this->client->getResponse()->getStatusCode()]);
    }

    private function createSiteOfAria(): string
    {
        $this->request('POST', '/api/mining-sites', ['label' => 'Belt Aldranette 2', 'solarSystemId' => self::ALDRANETTE]);

        return (string) ($this->responseJson()['id'] ?? '');
    }

    private function recordVeldsparScans(string $siteId): array
    {
        $responses = [];
        foreach (['20:00:00' => 30_000, '20:10:00' => 25_000, '20:20:00' => 18_000] as $time => $units) {
            self::mockTime(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'));
            $this->request('POST', sprintf('/api/mining-sites/%s/scans', $siteId), ['lines' => [['typeId' => self::VELDSPAR, 'units' => $units]]]);
            $responses[] = ['status' => $this->client->getResponse()->getStatusCode(), 'body' => $this->responseJson()];
        }

        return $responses;
    }

    private function storedClosedAtOf(string $siteId): ?string
    {
        $closedAt = $this->em->getConnection()->fetchOne('SELECT closed_at FROM mining_sites WHERE id = ?', [$siteId]);

        return $closedAt === false || $closedAt === null ? null : (string) $closedAt;
    }

    private function persistUser(): User
    {
        $user = new User();
        $this->em->persist($user);

        return $user;
    }

    private function persistSite(User $owner, string $label, ?int $solarSystemId, string $createdAt): MiningSite
    {
        $site = (new MiningSite())->setOwner($owner)->setLabel($label)->setSolarSystemId($solarSystemId)->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->em->persist($site);

        return $site;
    }

    private function persistScan(MiningSite $site, string $scannedAt, int $units): void
    {
        $this->em->persist((new MiningSiteScan())
            ->setSite($site)
            ->setScannedAt(new \DateTimeImmutable($scannedAt))
            ->setTotalUnits($units)
            ->setTotalVolumeM3($units * self::VELDSPAR_VOLUME)
            ->setRemainingValue($units * self::VELDSPAR_UNIT_PRICE));
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
        $this->replaceJitaBuyMarket();
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

    private function historyLineOf(array $site): array
    {
        return [
            $site['label'] ?? null,
            $site['solarSystemId'] ?? null,
            $site['status'] ?? null,
            $this->instant($site['firstScanAt'] ?? null),
            $this->instant($site['lastScanAt'] ?? null),
            $site['observedSeconds'] ?? null,
            $this->rounded($site['firstVolumeM3'] ?? null),
            $this->rounded($site['firstValue'] ?? null),
            $this->rounded($site['averageTrendRate'] ?? null),
            $this->rounded($site['remainingValue'] ?? null),
        ];
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    private function sortedKeysOfNonNullValues(array $resource): array
    {
        $keys = array_keys(array_filter($resource, static fn (mixed $value): bool => $value !== null));
        sort($keys);

        return $keys;
    }

    private function rounded(int|float|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function instant(?string $iso): ?string
    {
        return $iso === null ? null : (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s');
    }
}
