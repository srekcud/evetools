<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Tests\Functional\Api\MiningOperation\SeedsBoostShipSde;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-367')]
final class MiningSiteCompressionApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsBoostShipSde;
    use SeedsMiningSiteMarket;

    private const string SCANNED_AT = '2026-11-05T20:00:00+00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private string $porpoiseOperationId;
    private string $porpoiseSiteId;
    private string $porpoiseSiteWithoutScanId;
    private string $orcaSiteId;
    private string $unarmedSiteId;
    private string $privateSiteId;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::SCANNED_AT));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedMiningSiteSde($this->em);
        $this->seedBoostShipSde($this->em);
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $porpoise = $this->persistBoostedOperation('Porpoise run', MiningBoostShip::Porpoise, [MiningCompressorKind::Asteroid, MiningCompressorKind::Gas]);
        $orca = $this->persistBoostedOperation('Orca run', MiningBoostShip::Orca, [MiningCompressorKind::Asteroid]);
        $unarmed = $this->persistBoostedOperation('Unarmed run', null, []);

        $porpoiseSite = $this->persistSite($porpoise, 'Belt Aldranette', [self::VELDSPAR => 'present', self::ZEOLITES => 'present', self::BLUE_ICE => 'depleted']);
        $porpoiseSiteWithoutScan = $this->persistSite($porpoise, 'Anomalie vide', []);
        $orcaSite = $this->persistSite($orca, 'Belt Jaspet', [self::VELDSPAR => 'present', self::BLUE_ICE => 'present']);
        $unarmedSite = $this->persistSite($unarmed, 'Belt Sans Coque', [self::VELDSPAR => 'present']);
        $privateSite = $this->persistSite(null, 'Anomalie privée', [self::VELDSPAR => 'present']);
        $this->em->flush();

        $this->porpoiseOperationId = (string) $porpoise->getId();
        $this->porpoiseSiteId = (string) $porpoiseSite->getId();
        $this->porpoiseSiteWithoutScanId = (string) $porpoiseSiteWithoutScan->getId();
        $this->orcaSiteId = (string) $orcaSite->getId();
        $this->unarmedSiteId = (string) $unarmedSite->getId();
        $this->privateSiteId = (string) $privateSite->getId();
    }

    public function testSiteOfAnOperationJudgesEachPresentTypeOfItsLastScanAndLeavesOutTheDepletedOnes(): void
    {
        $compression = $this->compressionOf($this->porpoiseSiteId);

        self::assertSame(
            [
                self::PORPOISE,
                'Porpoise',
                [[self::VELDSPAR, true, 'on_site'], [self::ZEOLITES, false, 'no_hull_compressor']],
                ['noHull' => ['moon'], 'notMounted' => []],
                ['asteroid', 'gas'],
            ],
            [
                $compression['boostShipTypeId'] ?? null,
                $compression['boostShipName'] ?? null,
                array_map(static fn (array $verdict): array => [$verdict['typeId'], $verdict['compressible'], $verdict['status']], $compression['types'] ?? []),
                $compression['gaps'] ?? null,
                array_column($compression['mountedCompressors'] ?? [], 'kind'),
            ],
        );
    }

    public function testMissingCompressorOfTheHullIsAGapNotMountedWhileTheMountedKindStaysOnSite(): void
    {
        $compression = $this->compressionOf($this->orcaSiteId);

        self::assertSame(
            [[[self::VELDSPAR, 'on_site'], [self::BLUE_ICE, 'not_mounted']], ['noHull' => [], 'notMounted' => ['ice']]],
            [
                array_map(static fn (array $verdict): array => [$verdict['typeId'], $verdict['status']], $compression['types'] ?? []),
                $compression['gaps'] ?? null,
            ],
        );
    }

    public function testNoVerdictCanBeGivenWithoutABoostShipAndASiteWithoutScanHasNoTypeToJudge(): void
    {
        $unarmed = $this->compressionOf($this->unarmedSiteId);
        $withoutScan = $this->compressionOf($this->porpoiseSiteWithoutScanId);

        self::assertSame(
            [['none', [[self::VELDSPAR, null, 'no_boost_ship']], ['noHull' => [], 'notMounted' => []]], [self::PORPOISE, [], ['noHull' => [], 'notMounted' => []]]],
            [
                [
                    $unarmed['boostShipTypeId'] ?? 'none',
                    array_map(static fn (array $verdict): array => [$verdict['typeId'], $verdict['compressible'], $verdict['status']], $unarmed['types'] ?? []),
                    $unarmed['gaps'] ?? null,
                ],
                [$withoutScan['boostShipTypeId'] ?? 'none', $withoutScan['types'] ?? null, $withoutScan['gaps'] ?? null],
            ],
        );
    }

    public function testPrivateSiteHasNoCompressionBecauseTheHullIsASettingOfTheOperation(): void
    {
        $this->get('/api/mining-sites/'.$this->privateSiteId);

        self::assertSame([200, false], [$this->client->getResponse()->getStatusCode(), array_key_exists('compression', $this->responseJson()) && null !== $this->responseJson()['compression']]);
    }

    public function testListOfTheSitesOfAnOperationCarriesTheCompressionOfEachSite(): void
    {
        $this->get('/api/mining-sites?operationId='.$this->porpoiseOperationId);

        $sites = $this->responseJson();
        $gapsByLabel = [];
        foreach ($sites as $site) {
            $gapsByLabel[$site['label']] = $site['compression']['gaps'] ?? null;
        }
        ksort($gapsByLabel);

        self::assertSame(
            ['Anomalie vide' => ['noHull' => [], 'notMounted' => []], 'Belt Aldranette' => ['noHull' => ['moon'], 'notMounted' => []]],
            $gapsByLabel,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compressionOf(string $siteId): array
    {
        $this->get('/api/mining-sites/'.$siteId);
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson()['compression'] ?? [];
    }

    private function get(string $uri): void
    {
        $ariaId = (string) $this->aria->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $ariaId), 'api');
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<MiningCompressorKind> $mounted
     */
    private function persistBoostedOperation(string $label, ?MiningBoostShip $boostShip, array $mounted): MiningOperation
    {
        $operation = $this->persistMiningOperation($this->em, $this->aria, $label, self::SCANNED_AT, ownerCharacterNames: ['Aria Solberg']);
        $operation->declareBoostFit($boostShip, $mounted);

        return $operation;
    }

    /**
     * @param array<int, string> $statusByTypeId
     */
    private function persistSite(?MiningOperation $operation, string $label, array $statusByTypeId): MiningSite
    {
        $site = (new MiningSite())
            ->setOwner($this->aria)
            ->setOperation($operation)
            ->setLabel($label)
            ->setSolarSystemId(self::ALDRANETTE)
            ->setCreatedAt(new \DateTimeImmutable(self::SCANNED_AT));
        $this->em->persist($site);
        if ([] !== $statusByTypeId) {
            $this->em->persist((new MiningSiteScan())
                ->setSite($site)
                ->setAuthor($this->aria)
                ->setScannedAt(new \DateTimeImmutable(self::SCANNED_AT))
                ->setTotalUnits(1_000)
                ->setTotalVolumeM3(100.0)
                ->setRemainingValue(15_000.0)
                ->setTypes(array_map(
                    static fn (int $typeId, string $status): array => ['typeId' => $typeId, 'status' => $status, 'units' => 'present' === $status ? 1_000 : 0],
                    array_keys($statusByTypeId),
                    $statusByTypeId,
                )));
        }

        return $site;
    }
}
