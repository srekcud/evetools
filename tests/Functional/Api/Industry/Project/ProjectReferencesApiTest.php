<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

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

#[Group('issue-354')]
final class ProjectReferencesApiTest extends WebTestCase
{
    private const string REFERENCES_URI = '/api/industry/project-references';
    private const int MAREN_EVE_CHARACTER_ID = 2_354_900_101;
    private const int BRANN_EVE_CHARACTER_ID = 2_354_900_102;
    private const int ISHTAR = 12005;
    private const int ISHTAR_UNITS = 100;
    private const float ISHTAR_UNIT_COST = 20_000.0;
    private const float ISHTAR_MARGIN_PERCENT = 16.25;
    private const float AMOUNT_TOLERANCE = 0.0001;

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

    public function testProjectReferencesRequireAuthentication(): void
    {
        $this->client->request('GET', self::REFERENCES_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerReadsTheRealUnitCostAndTheMarginOfHisRecentSingleProductProject(): void
    {
        $project = $this->completedIshtarProject($this->maren, completedAgo: '-5 days');
        $this->em->flush();

        $references = $this->fetchAs($this->maren);

        self::assertSame(['id', 'rows'], array_slice(array_keys($references), -2));
        self::assertSame('project-references', $references['id']);
        self::assertCount(1, $references['rows']);
        $row = $references['rows'][0];
        self::assertSame(['productTypeId', 'projectId', 'completedAt', 'units', 'unitCost', 'marginPercent'], array_keys($row));
        self::assertSame([self::ISHTAR, $project->getId()->toRfc4122(), self::ISHTAR_UNITS], [$row['productTypeId'], $row['projectId'], $row['units']]);
        self::assertEqualsWithDelta(self::ISHTAR_UNIT_COST, $row['unitCost'], self::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta(self::ISHTAR_MARGIN_PERCENT, $row['marginPercent'], self::AMOUNT_TOLERANCE);
    }

    public function testProjectOlderThanAMonthIsIgnored(): void
    {
        $this->completedIshtarProject($this->maren, completedAgo: '-40 days');
        $this->em->flush();

        self::assertSame([], $this->fetchAs($this->maren)['rows']);
    }

    public function testProjectOfAnotherPilotIsNeverReturned(): void
    {
        $this->completedIshtarProject($this->brann, completedAgo: '-5 days');
        $this->em->flush();

        self::assertSame([], $this->fetchAs($this->maren)['rows']);
    }

    public function testAcceptedParticipantReadsTheReferenceOfTheProjectHeContributedTo(): void
    {
        $project = $this->completedIshtarProject($this->maren, completedAgo: '-5 days');
        $project->addMember((new IndustryProjectParticipant())
            ->setUser($this->brann)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted));
        $this->em->flush();

        $rows = $this->fetchAs($this->brann)['rows'];

        self::assertSame([$project->getId()->toRfc4122()], array_column($rows, 'projectId'));
    }

    public function testPendingParticipantDoesNotReadTheReference(): void
    {
        $project = $this->completedIshtarProject($this->maren, completedAgo: '-5 days');
        $project->addMember((new IndustryProjectParticipant())
            ->setUser($this->brann)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Pending));
        $this->em->flush();

        self::assertSame([], $this->fetchAs($this->brann)['rows']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAs(User $reader): array
    {
        $this->client->loginUser($reader, 'api');
        $this->client->request('GET', self::REFERENCES_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function createPilot(string $name, int $eveCharacterId): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_354_001)
            ->setCorporationName('Esk Ore Haulers');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function completedIshtarProject(User $owner, string $completedAgo): IndustryProject
    {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName('Ishtar batch')
            ->setStatus(ProjectStatus::Completed)
            ->setBrokerFeePercent(3.0)
            ->setSalesTaxPercent(4.0)
            ->setCompletedAt(new \DateTimeImmutable($completedAgo));
        $member = (new IndustryProjectParticipant())
            ->setUser($owner)
            ->setRole(ParticipantRole::Owner)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($member);
        $project->addItem((new IndustryProjectProduct())->setTypeId(self::ISHTAR)->setTypeName('Ishtar')->setRuns(self::ISHTAR_UNITS));
        $project->addBomItem((new IndustryProjectBomLine())
            ->setTypeId(self::ISHTAR)
            ->setTypeName('Ishtar')
            ->setRequiredQuantity(self::ISHTAR_UNITS)
            ->setIsJob(true)
            ->setActivityType('manufacturing')
            ->setParentTypeId(null));
        $bomLine = (new IndustryProjectBomLine())->setTypeId(34)->setTypeName('Tritanium')->setRequiredQuantity(1000);
        $project->addBomItem($bomLine);
        $project->addContribution((new IndustryProjectContribution())
            ->setMember($member)
            ->setBomItem($bomLine)
            ->setType(ContributionType::Material)
            ->setQuantity(1000)
            ->setEstimatedValue(2_000_000.0)
            ->setStatus(ContributionStatus::Approved));
        $project->addSale((new IndustryProjectSale())
            ->setTypeId(self::ISHTAR)
            ->setTypeName('Ishtar')
            ->setQuantity(10)
            ->setUnitPrice(250_000.0)
            ->setTotalPrice(2_500_000.0)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable('-1 day'))
            ->setRecordedBy($owner));
        $this->em->persist($project);

        return $project;
    }
}
