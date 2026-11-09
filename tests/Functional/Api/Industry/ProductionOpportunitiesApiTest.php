<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Character;
use App\Entity\User;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-356')]
final class ProductionOpportunitiesApiTest extends WebTestCase
{
    use SeedsJitaOrderBooksAndCostIndices;

    private const int EVE_CHARACTER_ID = 2_112_356_001;

    private KernelBrowser $client;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->recordEsiRequestsInsteadOfSendingThem();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        ManufacturableProductSde::persist($em);
        $character = (new Character())
            ->setEveCharacterId(self::EVE_CHARACTER_ID)
            ->setName('Vessa Harrow')
            ->setCorporationId(98_356_001)
            ->setCorporationName('Harrow Hull Works');
        $this->pilot = new User();
        $this->pilot->addCharacter($character);
        $this->pilot->setMainCharacter($character);
        $em->persist($this->pilot);
        $em->persist($character);
        $em->flush();
    }

    public function testAnonymousVisitorIsRejected(): void
    {
        $this->client->request('GET', '/api/industry/opportunities', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPilotWithoutSyncedSkillsGetsAnEmptyRankingWithUnknownSlotsAndNoCapitalMarket(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/industry/opportunities', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertNull($body['capitalCap']);
        self::assertNull($body['capitalStructureName']);
        self::assertNull($body['freeSlots']);
        self::assertSame(0.1, $body['sellShareOfDailyVolume']);
        self::assertFalse($body['salesFeesKnown']);
        self::assertIsInt($body['unknownProductCount']);
        self::assertSame([], $body['rows']);
    }

    public function testTheCapitalCapIsEchoedBack(): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/industry/opportunities?capital=59500000&sort=hour&market=jita', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(59_500_000, $this->responseJson()['capitalCap']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'negative capital' => ['capital=-5'];
        yield 'zero capital' => ['capital=0'];
        yield 'non numeric capital' => ['capital=lots'];
        yield 'unknown market' => ['market=amarr'];
        yield 'unknown sort' => ['sort=profit'];
    }

    #[DataProvider('invalidQueries')]
    public function testAnInvalidQueryIsRejected(string $query): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request('GET', '/api/industry/opportunities?'.$query, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
