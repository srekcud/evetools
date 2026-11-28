<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1400')]
final class PlanetaryProductionProfitApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T12:00:00+00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextEveCharacterId = 2_140_001_001;
    private int $nextEntryId = 7_140_000_001;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->alice = $this->persistPilot('Alice Tarn');
        $this->bob = $this->persistPilot('Bob Kess');
        $this->persistTax($this->alice->getMainCharacter(), CachedWalletJournalEntry::PLANETARY_IMPORT_TAX, -45_000.0, '2026-11-20T12:00:00+00:00');
        $this->persistTax($this->alice->getMainCharacter(), CachedWalletJournalEntry::PLANETARY_EXPORT_TAX, -45_000.0, '2026-11-21T12:00:00+00:00');
        $this->em->flush();
    }

    public function testTheProductionProfitRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/production-profit', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAUserSeesTheTaxesOfTheirOwnCharactersOnly(): void
    {
        $alice = $this->getProductionProfit($this->alice);
        $bob = $this->getProductionProfit($this->bob);

        self::assertEqualsWithDelta(3_000.0, $alice['taxes']['perDay'], 0.0001);
        self::assertSame([30, []], [$alice['taxes']['days'], $alice['lines']]);
        self::assertSame(['days' => 30, 'perDay' => null], $bob['taxes']);
    }

    #[Group('issue-1454')]
    public function testTheSourcesAreTheOnesOfTheFollowedPlanOfTheUserOnlyAndTheDefaultOtherwise(): void
    {
        $this->em->persist(
            (new PlanetaryPlanTemplate())
                ->setUser($this->alice)
                ->setName('Alice followed plan')
                ->setProductions([['typeId' => 2_389, 'targetPerDay' => 10]])
                ->setSettings(['purchaseSource' => 'jita-buy', 'saleSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4])
                ->setFollowed(true),
        );
        $this->em->flush();

        $alice = $this->getProductionProfit($this->alice);
        $bob = $this->getProductionProfit($this->bob);

        self::assertSame(['purchase' => 'jita-buy', 'sale' => 'jita-sell', 'followedTemplateName' => 'Alice followed plan'], $alice['sources']);
        self::assertSame(['purchase' => 'paid', 'sale' => 'hub', 'followedTemplateName' => null], $bob['sources']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProductionProfit(User $user): array
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
        $this->client->request('GET', '/api/planetary/production-profit', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function persistPilot(string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_540_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistTax(Character $character, string $refType, float $amount, string $date): void
    {
        $this->em->persist(
            (new CachedWalletJournalEntry())
                ->setCharacter($character)
                ->setJournalEntryId($this->nextEntryId++)
                ->setRefType($refType)
                ->setAmount($amount)
                ->setDate(new \DateTimeImmutable($date))
                ->setContextId(40_140_001)
                ->setContextIdType('planet_id'),
        );
    }
}
