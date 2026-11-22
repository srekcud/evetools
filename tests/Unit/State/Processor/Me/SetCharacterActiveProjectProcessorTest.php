<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Me\SetCharacterActiveProjectInput;
use App\ApiResource\Me\CharacterActiveProjectResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\CharacterActiveProject;
use App\State\Processor\Me\SetCharacterActiveProjectProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SetCharacterActiveProjectProcessor::class)]
#[Group('issue-1249')]
final class SetCharacterActiveProjectProcessorTest extends TestCase
{
    private const string UNKNOWN_ID = '01900000-0000-7000-8000-00000000dead';

    private User $caller;
    private Character $character;
    private Character $characterOfAnotherUser;
    private IndustryProject $project;
    private CharacterActiveProject&MockObject $characterActiveProject;
    private SetCharacterActiveProjectProcessor $processor;

    protected function setUp(): void
    {
        $this->caller = new User();
        $this->character = $this->characterOf($this->caller, 'Aria Solberg');
        $this->characterOfAnotherUser = $this->characterOf(new User(), 'Kel Dorn');
        $this->project = (new IndustryProject())->setName('Orca batch');
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v7());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->caller);
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?Character => match ((string) $id) {
                $this->character->getId()->toRfc4122() => $this->character,
                $this->characterOfAnotherUser->getId()->toRfc4122() => $this->characterOfAnotherUser,
                default => null,
            },
        );
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (mixed $id): ?IndustryProject => (string) $id === $this->project->getId()->toRfc4122() ? $this->project : null,
        );
        $this->characterActiveProject = $this->createMock(CharacterActiveProject::class);

        $this->processor = new SetCharacterActiveProjectProcessor($security, $characterRepository, $projectRepository, $this->characterActiveProject);
    }

    public function testItDedicatesTheCharacterToTheProjectAndReturnsTheUpdatedResource(): void
    {
        $this->characterActiveProject->expects($this->once())->method('dedicate')
            ->with($this->caller, $this->character, $this->project)
            ->willReturnCallback(fn (User $user, Character $character, ?IndustryProject $project) => $character->setActiveProject($project));

        $resource = $this->process($this->character, $this->input($this->project->getId()->toRfc4122()));

        self::assertSame($this->character->getId()->toRfc4122(), $resource->id);
        self::assertSame($this->project->getId()->toRfc4122(), $resource->activeProjectId);
        self::assertSame('Orca batch', $resource->activeProjectName);
    }

    public function testANullProjectIdClearsTheActiveProjectAndReturnsNullIdAndName(): void
    {
        $this->character->setActiveProject($this->project);
        $this->characterActiveProject->expects($this->once())->method('dedicate')
            ->with($this->caller, $this->character, null)
            ->willReturnCallback(fn (User $user, Character $character, ?IndustryProject $project) => $character->setActiveProject($project));

        $resource = $this->process($this->character, $this->input(null));

        self::assertSame($this->character->getId()->toRfc4122(), $resource->id);
        self::assertNull($resource->activeProjectId);
        self::assertNull($resource->activeProjectName);
    }

    public function testAMissingProjectIdKeyIsABadRequestAndChangesNothing(): void
    {
        $this->characterActiveProject->expects($this->never())->method('dedicate');

        $this->expectException(BadRequestHttpException::class);

        $this->process($this->character, new SetCharacterActiveProjectInput());
    }

    public function testACharacterOfAnotherUserIsNotFoundAndChangesNothing(): void
    {
        $this->characterActiveProject->expects($this->never())->method('dedicate');

        $this->expectException(NotFoundHttpException::class);

        $this->process($this->characterOfAnotherUser, $this->input($this->project->getId()->toRfc4122()));
    }

    public function testAnUnknownCharacterIsNotFound(): void
    {
        $this->characterActiveProject->expects($this->never())->method('dedicate');

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process($this->input($this->project->getId()->toRfc4122()), new Patch(), ['id' => self::UNKNOWN_ID]);
    }

    public function testAnUnknownProjectIsNotFoundAndChangesNothing(): void
    {
        $this->characterActiveProject->expects($this->never())->method('dedicate');

        $this->expectException(NotFoundHttpException::class);

        $this->process($this->character, $this->input(self::UNKNOWN_ID));
    }

    public function testAProjectIdThatIsNotAUuidIsNotFoundAndChangesNothing(): void
    {
        $this->characterActiveProject->expects($this->never())->method('dedicate');

        $this->expectException(NotFoundHttpException::class);

        $this->process($this->character, $this->input('not-a-uuid'));
    }

    public function testAProjectTheCallerCannotDedicateToIsNotFoundWhateverTheReasonTheServiceGives(): void
    {
        $this->characterActiveProject->expects($this->once())->method('dedicate')->willThrowException(new NotFoundHttpException('Project not found'));

        $this->expectException(NotFoundHttpException::class);

        $this->process($this->character, $this->input($this->project->getId()->toRfc4122()));
    }

    private function process(Character $character, SetCharacterActiveProjectInput $input): CharacterActiveProjectResource
    {
        return $this->processor->process($input, new Patch(), ['id' => $character->getId()->toRfc4122()]);
    }

    private function input(?string $projectId): SetCharacterActiveProjectInput
    {
        $input = new SetCharacterActiveProjectInput();
        $input->projectId = $projectId;

        return $input;
    }

    private function characterOf(User $user, string $name): Character
    {
        $character = (new Character())->setName($name);
        $user->addCharacter($character);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v7());

        return $character;
    }
}
