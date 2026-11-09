<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\ApiResource\Input\Industry\Project\CreateProjectTemplateInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Processor\Industry\Project\CreateProjectTemplateProcessor;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsProjectTemplates;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

#[CoversClass(CreateProjectTemplateProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-355')]
final class CreateProjectTemplateProcessorTest extends TestCase
{
    use BuildsProjectTemplates;

    private const int ALICE_CORPORATION_ID = 98_000_001;

    private EntityManagerInterface&MockObject $entityManager;
    private IndustryProjectTemplateRepository $templateRepository;
    private IndustryProjectParticipantRepository $memberRepository;
    private User $alice;
    private IndustryProject $hammerheadBatch;
    private int $templatesOfAlice = 0;

    protected function setUp(): void
    {
        $this->alice = $this->pilot('Alice Ferrand', self::ALICE_CORPORATION_ID);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->hammerheadBatch = (new IndustryProject())->setOwner($this->alice)->setName('Hammerhead batch');
        $this->hammerheadBatch->addItem($this->productOf(self::HAMMERHEAD_II_TYPE_ID, 'Hammerhead II', 10, 20, 50, 0));
        $this->hammerheadBatch->addItem($this->productOf(self::WARRIOR_II_TYPE_ID, 'Warrior II', 5, 10, 20, 1));
        $this->hammerheadBatch->setBlacklistGroupIds([18]);
        $this->hammerheadBatch->setBlacklistTypeIds([34]);
        $this->hammerheadBatch->setAcceptsOutsideFavoriteSystem(true);
        $this->hammerheadBatch->setMinimumOutsideSaving(5_000_000.0);

        $this->templateRepository = $this->createStub(IndustryProjectTemplateRepository::class);
        $this->templateRepository->method('countByOwner')->willReturnCallback(fn (): int => $this->templatesOfAlice);
        $this->memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
    }

    public function testTemplateCopiesTheItemsTheBlacklistAndTheOutsideFavoriteSystemPolicyOfTheProject(): void
    {
        $persisted = $this->capturePersistedTemplate();

        $resource = $this->process($this->alice, $this->inputFor('Hammerhead line', false));

        $template = $persisted[0] ?? self::fail('No template persisted');
        self::assertSame('Hammerhead line', $template->getName());
        self::assertSame($this->alice, $template->getOwner());
        self::assertSame([
            ['typeId' => 2185, 'typeName' => 'Hammerhead II', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 50],
            ['typeId' => 2446, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20],
        ], $template->getItems());
        self::assertSame([18], $template->getBlacklistGroupIds());
        self::assertSame([34], $template->getBlacklistTypeIds());
        self::assertTrue($template->isAcceptsOutsideFavoriteSystem());
        self::assertSame(5_000_000.0, $template->getMinimumOutsideSaving());
        self::assertFalse($template->isSharedWithCorporation());
        self::assertSame((string) $template->getId(), $resource->id);
        self::assertTrue($resource->isOwn);
        self::assertSame('Alice Ferrand', $resource->ownerName);
    }

    #[Group('issue-50')]
    public function testTemplateCopiesTheSellStructureOfTheProjectAndExposesIt(): void
    {
        $this->hammerheadBatch->setSellStructure(1042000000001, 'Keepstar A');
        $persisted = $this->capturePersistedTemplate();

        $resource = $this->process($this->alice, $this->inputFor('Hammerhead line', false));

        $template = $persisted[0] ?? self::fail('No template persisted');
        self::assertSame([1042000000001, 'Keepstar A'], [$template->getSellStructureId(), $template->getSellStructureName()]);
        self::assertSame([1042000000001, 'Keepstar A'], [$resource->sellStructureId, $resource->sellStructureName]);
    }

    #[Group('issue-50')]
    public function testTemplateOfAProjectWithoutSellStructureHasNone(): void
    {
        $persisted = $this->capturePersistedTemplate();

        $resource = $this->process($this->alice, $this->inputFor('Hammerhead line', false));

        $template = $persisted[0] ?? self::fail('No template persisted');
        self::assertSame([null, null], [$template->getSellStructureId(), $template->getSellStructureName()]);
        self::assertSame([null, null], [$resource->sellStructureId, $resource->sellStructureName]);
    }

    public function testProjectWithAnEmptyBlacklistGivesATemplateWithEmptyListsNeverNull(): void
    {
        $this->hammerheadBatch->setBlacklistGroupIds([]);
        $this->hammerheadBatch->setBlacklistTypeIds([]);
        $persisted = $this->capturePersistedTemplate();

        $resource = $this->process($this->alice, $this->inputFor('Hammerhead line', true));

        $template = $persisted[0] ?? self::fail('No template persisted');
        self::assertSame([], $template->getBlacklistGroupIds());
        self::assertSame([], $template->getBlacklistTypeIds());
        self::assertTrue($template->isSharedWithCorporation());
        self::assertSame([], $resource->blacklistGroupIds);
        self::assertSame([], $resource->blacklistTypeIds);
    }

    public function testFiftyFirstTemplateOfAnOwnerIsRefusedAndNothingIsAdded(): void
    {
        $this->templatesOfAlice = IndustryProjectTemplate::MAXIMUM_PER_OWNER;
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->process($this->alice, $this->inputFor('Fifty-first line', false));
    }

    public function testUserWhoIsNotAParticipantOfTheProjectCannotSaveATemplateFromIt(): void
    {
        $bob = $this->pilot('Bob Lenoir', self::ALICE_CORPORATION_ID);
        $this->memberRepository->method('findOneBy')->willReturn(null);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('You do not have access to this project');

        $this->process($bob, $this->inputFor('Hammerhead line', false));
    }

    private function process(User $actor, CreateProjectTemplateInput $input): IndustryProjectTemplateResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->hammerheadBatch);

        $processor = new CreateProjectTemplateProcessor(
            $security,
            $projectRepository,
            new ProjectAccessChecker($this->memberRepository),
            $this->templateRepository,
            $this->entityManager,
        );

        return $processor->process($input, new Post());
    }

    private function inputFor(string $name, bool $sharedWithCorporation): CreateProjectTemplateInput
    {
        $input = new CreateProjectTemplateInput();
        $input->projectId = $this->hammerheadBatch->getId()->toRfc4122();
        $input->name = $name;
        $input->sharedWithCorporation = $sharedWithCorporation;

        return $input;
    }

    /** @return \ArrayObject<int, IndustryProjectTemplate> */
    private function capturePersistedTemplate(): \ArrayObject
    {
        $persisted = new \ArrayObject();
        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(
            static function (object $entity) use ($persisted): void {
                if ($entity instanceof IndustryProjectTemplate) {
                    $persisted[] = $entity;
                }
            },
        );
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        return $persisted;
    }

    private function productOf(int $typeId, string $typeName, int $meLevel, int $teLevel, int $runs, int $sortOrder): IndustryProjectProduct
    {
        return (new IndustryProjectProduct())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setRuns($runs)
            ->setSortOrder($sortOrder);
    }
}
