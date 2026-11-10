<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningSite;

use App\Entity\MiningSite;
use App\Entity\User;
use App\Tests\Integration\MiningOperation\BuildsHaulerPlanOperation;
use App\Tests\Integration\MiningOperation\ShiftsLedgerFreshness;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-267')]
final class MiningSiteLedgerEtaApiTest extends WebTestCase
{
    use BuildsHaulerPlanOperation;
    use ClockSensitiveTrait;
    use ShiftsLedgerFreshness;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $operationSiteId;
    private string $freeSiteId;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::HAULER_PLAN_NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedHaulerPlanSde();
        $operation = $this->persistHaulerPlanOperation();
        $this->freshenLedgerEntries(['Aria Solberg' => '2026-11-10T20:04:00+00:00', 'Brann Holt' => '2026-11-10T20:10:00+00:00', 'Cyra Lune' => '2026-11-10T20:06:00+00:00']);
        $freeSite = (new MiningSite())->setOwner($this->aria)->setLabel('Free belt')->setCreatedAt(new \DateTimeImmutable(self::HAULER_PLAN_STARTED_AT));
        $this->em->persist($freeSite);
        $this->em->flush();
        $operationSite = $this->em->getRepository(MiningSite::class)->findOneBy(['operation' => $operation]);
        $this->operationSiteId = (string) $operationSite?->getId();
        $this->freeSiteId = (string) $freeSite->getId();
    }

    public function testSiteOfARunningOperationCarriesTheApproximateEtaOfTheFleetLedger(): void
    {
        $site = $this->get($this->operationSiteId);

        self::assertSame(['2026-11-11T16:59:00+00:00', null], [
            null === ($site['ledgerEta'] ?? null) ? null : (new \DateTimeImmutable($site['ledgerEta']))->format(\DateTimeInterface::ATOM),
            $site['ledgerEtaUnknownReason'] ?? null,
        ]);
    }

    public function testFreeSiteCarriesNoLedgerEtaAndNoReason(): void
    {
        $site = $this->get($this->freeSiteId);

        self::assertSame([true, true], [null === ($site['ledgerEta'] ?? null), null === ($site['ledgerEtaUnknownReason'] ?? null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $siteId): array
    {
        $ariaId = (string) $this->aria->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $ariaId), 'api');
        $this->client->request('GET', '/api/mining-sites/'.$siteId, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
