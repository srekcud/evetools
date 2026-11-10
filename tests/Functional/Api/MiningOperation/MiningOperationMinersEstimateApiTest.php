<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use App\Tests\Integration\MiningSite\SeedsMiningSiteMarket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-309')]
final class MiningOperationMinersEstimateApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsMiningSiteMarket;

    private const string NOW = '2026-11-10T20:10:00+00:00';
    private const string START = '2026-11-11T09:40:00+00:00';
    private const string ENROLLED_AT = '2026-10-01T00:00:00+00:00';
    private const array LIFESPAN_MINUTES = [60, 90, 120];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $ariaId;
    private string $outsiderId;
    private string $operationId;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedMiningSiteSde($this->em);
        $aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1);
        $outsider = $this->persistMiningPilot($this->em, 'Zed Outsider', self::C2);
        $operation = $this->persistMiningOperation($this->em, $aria, 'Anomalie Veldspar', self::START, ownerCharacterNames: ['Aria Solberg']);
        foreach ($operation->getMembers() as $member) {
            (new \ReflectionProperty(MiningOperationMember::class, 'joinedAt'))->setValue($member, new \DateTimeImmutable(self::ENROLLED_AT));
            foreach ($member->getCharacters() as $enrolment) {
                (new \ReflectionProperty(MiningOperationCharacter::class, 'enrolledAt'))->setValue($enrolment, new \DateTimeImmutable(self::ENROLLED_AT));
            }
        }
        $this->em->flush();
        foreach (self::LIFESPAN_MINUTES as $index => $minutes) {
            $first = (new \DateTimeImmutable(self::NOW))->modify(sprintf('-%d days', 20 - $index));
            $site = (new MiningSite())->setOwner($aria)->setOperation($operation)->setLabel(sprintf('Site %d', $index + 1))->setStatus(MiningSiteStatus::Closed)->setCreatedAt($first)->setClosedAt($first->modify(sprintf('+%d minutes', $minutes)));
            $this->em->persist($site);
            $this->em->persist((new MiningSiteScan())->setSite($site)->setAuthor($aria)->setScannedAt($first)->setTotalUnits(60_000)->setTotalVolumeM3(6_000.0)->setRemainingValue(900_000.0)->setTypes([['typeId' => self::VELDSPAR, 'status' => 'present', 'units' => 60_000]]));
            $this->em->persist((new MiningSiteScan())->setSite($site)->setAuthor($aria)->setScannedAt($first->modify(sprintf('+%d minutes', $minutes)))->setTotalUnits(0)->setTotalVolumeM3(0.0)->setRemainingValue(0.0)->setTypes([['typeId' => self::VELDSPAR, 'status' => 'depleted', 'units' => 0]]));
        }
        $this->em->flush();
        $this->ariaId = (string) $aria->getId();
        $this->outsiderId = (string) $outsider->getId();
        $this->operationId = (string) $operation->getId();
    }

    public function testParticipantGetsTheMinersNeededOfEachTypeFromTheirDepletedSites(): void
    {
        $payload = $this->get($this->ariaId, 200);

        self::assertSame(self::START, (new \DateTimeImmutable($payload['startAt']))->format(\DateTimeInterface::ATOM));
        self::assertCount(1, $payload['types']);
        $veldspar = $payload['types'][0];
        self::assertSame(
            [self::VELDSPAR, 'Veldspar', 'estimated', 3, 90.0, 2, 'Site 3'],
            [$veldspar['typeId'], $veldspar['typeName'], $veldspar['status'], $veldspar['knownSiteCount'], $veldspar['workMinerMinutes'], $veldspar['minersNeeded'], $veldspar['sites'][0]['label']],
        );
        self::assertSame([4, 5, 6, 7, 8, 9], array_column($veldspar['durations'], 'miners'));
    }

    public function testSiteRowCarriesItsMinersLifespanAndWorkload(): void
    {
        $types = $this->get($this->ariaId, 200)['types'];
        self::assertCount(1, $types);
        $site = $types[0]['sites'][0];

        self::assertSame([1, 120.0, 120.0, null], [$site['miners'], $site['lifespanMinutes'], $site['workMinerMinutes'], $site['unknownReason']]);
    }

    public function testNonParticipantGetsTheSameAnswerAsAnUnknownOperation(): void
    {
        $payload = $this->get($this->outsiderId, 404);

        self::assertSame('Mining operation not found', $payload['detail'] ?? $payload['hydra:description'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $userId, int $expectedStatus): array
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request('GET', '/api/mining-operations/'.$this->operationId.'/miners-estimate', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame($expectedStatus);
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
