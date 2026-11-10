<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Mining;

use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-532')]
final class MiningValuationProfileApiTest extends WebTestCase
{
    private const string ENDPOINT = '/api/mining/valuation-profile';
    private const int STRUCTURE_ID = 1_022_734_985_679;
    private const string STRUCTURE_NAME = 'Keepstar Z-9';
    private const string STRUCTURE_REQUIRED_MESSAGE = 'A structure is required for the structure market.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pilot = new User();
        $this->em->persist($this->pilot);
        $this->em->flush();
    }

    public function testAPilotWithoutProfileGetsTheDefaultsFlaggedNotPersistedAndNothingIsWrittenByTheRead(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('GET');

        self::assertResponseStatusCodeSame(200);
        $json = $this->responseJson();
        foreach (['structureId', 'structureName'] as $nullableKey) {
            self::assertArrayHasKey($nullableKey, $json);
            self::assertNull($json[$nullableKey]);
        }
        self::assertSame('jita', $json['market'] ?? null);
        self::assertSame('buy', $json['side'] ?? null);
        self::assertSame('compressed', $json['form'] ?? null);
        self::assertSame(0.0, $json['exportTax'] ?? null);
        self::assertFalse($json['persisted'] ?? null);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testPatchingCreatesTheProfileOfThePilotAndTheDatabaseHoldsTheSentValues(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', [
            'market' => 'structure',
            'side' => 'sell',
            'form' => 'raw',
            'structureId' => self::STRUCTURE_ID,
            'structureName' => self::STRUCTURE_NAME,
            'exportTax' => 1250.0,
        ]);

        self::assertResponseStatusCodeSame(200);
        $json = $this->responseJson();
        self::assertSame(
            ['structure', 'sell', 'raw', self::STRUCTURE_ID, self::STRUCTURE_NAME, 1250.0, true],
            [$json['market'] ?? null, $json['side'] ?? null, $json['form'] ?? null, $json['structureId'] ?? null, $json['structureName'] ?? null, $json['exportTax'] ?? null, $json['persisted'] ?? null],
        );
        $stored = $this->storedProfile();
        self::assertSame(
            [MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Raw, self::STRUCTURE_ID, self::STRUCTURE_NAME, 1250.0],
            [$stored->getMarket(), $stored->getSide(), $stored->getForm(), $stored->getStructureId(), $stored->getStructureName(), $stored->getExportTax()],
        );
        self::assertSame(1, $this->storedProfileCount());
    }

    public function testPatchingAnExistingProfileUpdatesItInPlaceAndKeepsASingleRowForThePilot(): void
    {
        $this->em->persist((new MiningValuationProfile())
            ->setUser($this->pilot)
            ->setMarket(MiningValuationMarket::Structure)
            ->setSide(MarketSide::Sell)
            ->setForm(MiningValuationForm::Reprocessed)
            ->setStructureId(self::STRUCTURE_ID)
            ->setStructureName(self::STRUCTURE_NAME)
            ->setExportTax(850.0));
        $this->em->flush();
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['market' => 'jita', 'exportTax' => 0.0]);

        self::assertResponseStatusCodeSame(200);
        $stored = $this->storedProfile();
        self::assertSame(
            [MiningValuationMarket::Jita, MarketSide::Sell, MiningValuationForm::Reprocessed, self::STRUCTURE_ID, self::STRUCTURE_NAME, 0.0],
            [$stored->getMarket(), $stored->getSide(), $stored->getForm(), $stored->getStructureId(), $stored->getStructureName(), $stored->getExportTax()],
        );
        self::assertSame(1, $this->storedProfileCount());
    }

    public function testTheStructureMarketWithoutAStructureIsRefusedWithA422AndNothingIsStored(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['market' => 'structure']);

        self::assertResponseStatusCodeSame(422);
        $violations = $this->responseJson()['violations'] ?? [];
        self::assertSame([['structureId', self::STRUCTURE_REQUIRED_MESSAGE]], array_map(static fn (array $violation): array => [$violation['propertyPath'] ?? null, $violation['message'] ?? null], $violations));
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testANegativeExportTaxIsRefusedWithA422AndNothingIsStored(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['exportTax' => -1.0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testAnUnknownMarketIsRefusedWithA422AndNothingIsStored(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['market' => 'hisec']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testAStructureIdOfZeroIsRefusedWithA422AndNothingIsStored(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['market' => 'structure', 'structureId' => 0, 'structureName' => 'Nowhere']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testAStructureNameOf256CharactersIsRefusedWithA422InsteadOfAServerError(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->send('PATCH', ['market' => 'structure', 'structureId' => self::STRUCTURE_ID, 'structureName' => str_repeat('K', 256)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testAStructureIdBeyondTheBigintRangeIsRefusedAsAMisformattedBodyInsteadOfAServerError(): void
    {
        $this->client->loginUser($this->pilot, 'api');

        $this->client->request(
            'PATCH',
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"market":"structure","structureId":9223372036854775808,"structureName":"Beyond"}',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->storedProfileCount());
    }

    public function testAnAnonymousVisitorCannotReadTheProfile(): void
    {
        $this->send('GET');

        self::assertResponseStatusCodeSame(401);
    }

    private function send(string $method, ?array $body = null): void
    {
        $this->client->request(
            $method,
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = $content === '' ? [] : json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function storedProfileCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_mining_valuation_profiles WHERE user_id = ?', [(string) $this->pilot->getId()]);
    }

    private function storedProfile(): MiningValuationProfile
    {
        $this->em->clear();
        $stored = self::getContainer()->get(MiningValuationProfileRepository::class)->findOneBy(['user' => $this->em->find(User::class, $this->pilot->getId())]);
        self::assertInstanceOf(MiningValuationProfile::class, $stored);

        return $stored;
    }
}
