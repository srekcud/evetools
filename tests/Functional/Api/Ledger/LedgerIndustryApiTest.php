<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Ledger;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-360')]
final class LedgerIndustryApiTest extends WebTestCase
{
    private const int MAREN_EVE_CHARACTER_ID = 2_112_900_101;
    private const int BRANN_EVE_CHARACTER_ID = 2_112_900_102;
    private const int SABRE = 22456;
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const float AMOUNT_TOLERANCE = 0.01;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $maren;
    private User $brann;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->maren = $this->createPilot('Maren Esk', self::MAREN_EVE_CHARACTER_ID);
        $this->brann = $this->createPilot('Brann Holt', self::BRANN_EVE_CHARACTER_ID);
    }

    public function testIndustryRevenueRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/ledger/industry?days=30', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownProfitProjectKeepsEveryUnknownFigureAsNullInTheResponse(): void
    {
        $this->soldProject($this->maren, 'Hecate', self::HECATE, unitPrice: 2_000_000.0, brokerFeePercent: null);
        $this->em->flush();

        $industry = $this->fetch('/api/ledger/industry?days=30');

        self::assertSame(['id', 'period', 'totals', 'months', 'products'], array_slice(array_keys($industry), -5));
        self::assertSame(['from', 'to', 'days'], array_keys($industry['period']));
        self::assertSame(30, $industry['period']['days']);
        self::assertSame(
            ['profit' => 0.0, 'projectCount' => 1, 'unknownProjectCount' => 1, 'capital' => 0.0, 'slotHours' => null, 'iskPerHour' => null],
            $industry['totals'],
        );
        self::assertSame(
            [[
                'month' => (new \DateTimeImmutable('-5 days', new \DateTimeZone('UTC')))->format('Y-m'),
                'projectCount' => 1,
                'unknownProjectCount' => 1,
                'profit' => 0.0,
                'capital' => 0.0,
                'marginPercent' => null,
                'slotHours' => null,
                'iskPerHour' => null,
            ]],
            $industry['months'],
        );
        self::assertSame(
            [['typeId' => self::HECATE, 'typeName' => 'Hecate', 'projectCount' => 1, 'unknownProjectCount' => 1, 'profit' => 0.0]],
            $industry['products'],
        );
    }

    public function testIndustryRevenueShowsOnlyTheProjectsOfTheConnectedUser(): void
    {
        $this->soldProject($this->maren, 'Sabre', self::SABRE, unitPrice: 1_000_000.0, contributionValue: 5_000_000.0);
        $this->soldProject($this->brann, 'Ishtar', self::ISHTAR, unitPrice: 3_000_000.0, contributionValue: 10_000_000.0);
        $this->em->flush();

        $industry = $this->fetch('/api/ledger/industry?days=30');

        self::assertSame(['Sabre'], array_column($industry['products'], 'typeName'));
        self::assertSame(1, $industry['totals']['projectCount']);
        self::assertEqualsWithDelta(4_300_000.0, $industry['totals']['profit'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(5_000_000.0, $industry['totals']['capital'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(4_300_000.0, $industry['months'][0]['profit'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(86.0, $industry['months'][0]['marginPercent'], self::AMOUNT_TOLERANCE);
    }

    public function testDashboardExposesTheIndustryProfitItsBreakdownAndItsShare(): void
    {
        $this->soldProject($this->maren, 'Sabre', self::SABRE, unitPrice: 1_000_000.0, contributionValue: 5_000_000.0);
        $this->soldProject($this->maren, 'Hecate', self::HECATE, unitPrice: 2_000_000.0, brokerFeePercent: null);
        $this->em->flush();

        $dashboard = $this->fetch('/api/ledger/dashboard?days=30');

        self::assertEqualsWithDelta(4_300_000.0, $dashboard['totals']['industry'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(4_300_000.0, $dashboard['totals']['total'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(4_300_000.0, $dashboard['industryBreakdown']['profit'], self::AMOUNT_TOLERANCE);
        self::assertSame(2, $dashboard['industryBreakdown']['projectCount']);
        self::assertSame(1, $dashboard['industryBreakdown']['unknownProjectCount']);
        self::assertEqualsWithDelta(100.0, $dashboard['industryPercent'], self::AMOUNT_TOLERANCE);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $uri): array
    {
        $this->client->loginUser($this->maren, 'api');
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function createPilot(string $name, int $eveCharacterId): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_900_001)
            ->setCorporationName('Esk Ore Haulers');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function soldProject(
        User $owner,
        string $productName,
        int $productTypeId,
        float $unitPrice,
        float $contributionValue = 2_000_000.0,
        ?float $brokerFeePercent = 3.0,
    ): void {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName($productName . ' batch')
            ->setStatus(ProjectStatus::Completed)
            ->setBrokerFeePercent($brokerFeePercent)
            ->setSalesTaxPercent(4.0)
            ->setCompletedAt(new \DateTimeImmutable('-5 days'));
        $member = (new IndustryProjectParticipant())
            ->setUser($owner)
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);
        $project->addItem((new IndustryProjectProduct())->setTypeId($productTypeId)->setTypeName($productName)->setRuns(1));
        $bomLine = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1000);
        $project->addBomItem($bomLine);
        $project->addContribution((new IndustryProjectContribution())
            ->setMember($member)
            ->setBomItem($bomLine)
            ->setType(ContributionType::Material)
            ->setQuantity(1000)
            ->setEstimatedValue($contributionValue)
            ->setStatus(ContributionStatus::Approved));
        $project->addSale((new IndustryProjectSale())
            ->setTypeId(0)
            ->setTypeName('Sale')
            ->setQuantity(10)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice(10 * $unitPrice)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable('-6 days'))
            ->setRecordedBy($owner));
        $this->em->persist($project);
    }
}
