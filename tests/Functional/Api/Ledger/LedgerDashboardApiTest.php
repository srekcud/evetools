<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Ledger;

use App\Entity\Character;
use App\Entity\MiningEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LedgerDashboardApiTest extends WebTestCase
{
    private const int EVE_CHARACTER_ID = 2_112_900_001;
    private const int UNPRICED_ORE_TYPE_ID = 999_999_001;

    private KernelBrowser $client;
    private User $pilot;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Maren Esk')
            ->setCorporationId(98_900_001)
            ->setCorporationName('Esk Ore Haulers');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $this->em->persist($this->pilot);
        $this->em->persist($character);
        $this->em->flush();
    }

    public function testUnknownMiningValueKeepsIncomeSharesInTheResponseAsUnknown(): void
    {
        $this->em->persist((new MiningEntry())
            ->setUser($this->pilot)
            ->setCharacterId(self::EVE_CHARACTER_ID)
            ->setCharacterName('Maren Esk')
            ->setDate(new \DateTimeImmutable('today'))
            ->setTypeId(self::UNPRICED_ORE_TYPE_ID)
            ->setTypeName('Unpriced Ore')
            ->setSolarSystemId(30003504)
            ->setSolarSystemName('Aldranette')
            ->setQuantity(4211));
        $this->em->flush();

        $dashboard = $this->fetchDashboard();

        self::assertNull($dashboard['totals']['mining']);
        self::assertSame(
            ['iskPerDay' => null, 'pvePercent' => null, 'miningPercent' => null],
            array_intersect_key($dashboard, array_flip(['iskPerDay', 'pvePercent', 'miningPercent'])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDashboard(): array
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/ledger/dashboard?days=30', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
