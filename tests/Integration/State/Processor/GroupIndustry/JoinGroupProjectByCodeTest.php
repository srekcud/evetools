<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectProduct;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\JoinGroupProjectProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;

#[Group('issue-362')]
final class JoinGroupProjectByCodeTest extends IntegrationTestCase
{
    private const int OWNER_CORPORATION_ID = 98_362_301;
    private const int OTHER_CORPORATION_ID = 98_362_302;

    private const string PRIVATE_PUBLISHED_CODE = 'e8c0de0001';
    private const string OPEN_DRAFT_CODE = 'e11c0de002';
    private const string OPEN_PUBLISHED_CODE = 'e9c0de0003';
    private const string OPEN_IN_PROGRESS_CODE = 'e9c0de0004';
    private const string OPEN_WITHOUT_PRODUCT_CODE = 'e5c0de0005';

    private string $corpmateId;
    private string $strangerId;
    private string $withoutProductProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->pilot('Aria Solberg', self::OWNER_CORPORATION_ID);
        $corpmate = $this->pilot('Brann Holt', self::OWNER_CORPORATION_ID);
        $stranger = $this->pilot('Nyx Arden', self::OTHER_CORPORATION_ID);

        $this->project($owner, 'Private published', GroupProjectStatus::Published, true, self::PRIVATE_PUBLISHED_CODE);
        $this->project($owner, 'Open draft', GroupProjectStatus::Draft, false, self::OPEN_DRAFT_CODE);
        $this->project($owner, 'Open published', GroupProjectStatus::Published, false, self::OPEN_PUBLISHED_CODE);
        $this->project($owner, 'Open in progress', GroupProjectStatus::InProgress, false, self::OPEN_IN_PROGRESS_CODE);
        $withoutProduct = $this->project($owner, 'Open without product', GroupProjectStatus::Published, false, self::OPEN_WITHOUT_PRODUCT_CODE, withProduct: false);
        $this->em->flush();

        $this->corpmateId = (string) $corpmate->getId();
        $this->strangerId = (string) $stranger->getId();
        $this->withoutProductProjectId = (string) $withoutProduct->getId();
        $this->em->clear();
    }

    public function testPrivatePublishedProjectIsNotFoundByItsCodeLikeAnUnknownCode(): void
    {
        $privateRefusal = $this->refusalOfJoining($this->corpmateId, self::PRIVATE_PUBLISHED_CODE);
        $unknownRefusal = $this->refusalOfJoining($this->corpmateId, 'ffffffffff');

        self::assertInstanceOf(NotFoundHttpException::class, $privateRefusal);
        self::assertSame('Project not found', $privateRefusal->getMessage());
        self::assertSame($unknownRefusal->getMessage(), $privateRefusal->getMessage());
        self::assertSame(0, $this->participantsOf('Private published', GroupMemberRole::Member));
    }

    public function testOpenDraftProjectIsClosedToNewMembers(): void
    {
        $refusal = $this->refusalOfJoining($this->corpmateId, self::OPEN_DRAFT_CODE);

        self::assertInstanceOf(BadRequestHttpException::class, $refusal);
        self::assertSame('This project is no longer accepting new members', $refusal->getMessage());
        self::assertSame(0, $this->participantsOf('Open draft', GroupMemberRole::Member));
    }

    public function testCorpmateIsAcceptedAndStrangerIsPendingOnAnOpenPublishedProject(): void
    {
        $corpmateResource = $this->join($this->corpmateId, self::OPEN_PUBLISHED_CODE);
        $strangerResource = $this->join($this->strangerId, self::OPEN_PUBLISHED_CODE);

        self::assertSame(['member', 'accepted'], [$corpmateResource->myRole, $corpmateResource->myStatus]);
        self::assertSame(['member', 'pending'], [$strangerResource->myRole, $strangerResource->myStatus]);
        self::assertSame(2, $this->participantsOf('Open published', GroupMemberRole::Member));
    }

    public function testCorpmateIsAcceptedOnAnOpenInProgressProject(): void
    {
        $resource = $this->join($this->corpmateId, self::OPEN_IN_PROGRESS_CODE);

        self::assertSame(['member', 'accepted'], [$resource->myRole, $resource->myStatus]);
    }

    public function testProjectWithoutProductIsFoundByItsCodeAndJoined(): void
    {
        $project = self::getContainer()->get(IndustryProjectRepository::class)->findByShortLinkCode(self::OPEN_WITHOUT_PRODUCT_CODE);

        self::assertInstanceOf(IndustryProject::class, $project);
        self::assertSame($this->withoutProductProjectId, (string) $project->getId());
        self::assertCount(0, $project->getItems());
        self::assertSame('accepted', $this->join($this->corpmateId, self::OPEN_WITHOUT_PRODUCT_CODE)->myStatus);
    }

    private function join(string $userId, string $code): GroupIndustryProjectResource
    {
        return $this->processorActingAs($userId)->process(null, new Post(), ['shortLinkCode' => $code]);
    }

    private function refusalOfJoining(string $userId, string $code): \Throwable
    {
        try {
            $this->join($userId, $code);
        } catch (NotFoundHttpException|BadRequestHttpException $refusal) {
            return $refusal;
        }

        self::fail('Joining was expected to be refused');
    }

    private function participantsOf(string $projectName, GroupMemberRole $role): int
    {
        $this->em->clear();
        $project = $this->em->getRepository(IndustryProject::class)->findOneBy(['name' => $projectName]);
        \assert($project instanceof IndustryProject);

        return $project->getMembers()->filter(static fn (IndustryProjectParticipant $participant): bool => $participant->getRole() === $role)->count();
    }

    private function processorActingAs(string $userId): JoinGroupProjectProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $userId));

        return new JoinGroupProjectProcessor(
            $security,
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
            self::getContainer()->get(EntityManagerInterface::class),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    private function pilot(string $name, int $corporationId): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $name)->setCorporationId($corporationId));

        return $user;
    }

    private function project(User $owner, string $name, GroupProjectStatus $status, bool $private, string $code, bool $withProduct = true): IndustryProject
    {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName($name)
            ->setStatus($status)
            ->setPrivate($private)
            ->setShortLinkCode($code);
        if ($withProduct) {
            $project->addItem((new IndustryProjectProduct())->setTypeId(22456)->setTypeName('Sabre')->setMeLevel(10)->setTeLevel(20)->setRuns(5));
        }
        $this->em->persist($project);
        $project->addMember((new IndustryProjectParticipant())->setUser($owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted));

        return $project;
    }
}
