<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\Patch;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\State\Provider\Me\CharacterActiveProjectProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CharacterActiveProjectProvider::class)]
#[Group('issue-1249')]
final class CharacterActiveProjectProviderTest extends TestCase
{
    private const string UNKNOWN_CHARACTER_ID = '01900000-0000-7000-8000-00000000dead';

    private User $caller;
    private Character $character;
    private Character $characterOfAnotherUser;
    private CharacterActiveProjectProvider $provider;

    protected function setUp(): void
    {
        $this->caller = new User();
        $this->character = $this->characterOf($this->caller, 'Aria Solberg');
        $this->characterOfAnotherUser = $this->characterOf(new User(), 'Kel Dorn');

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

        $this->provider = new CharacterActiveProjectProvider($security, $characterRepository);
    }

    public function testItReturnsTheActiveProjectIdAndNameOfTheCharacterOfTheCaller(): void
    {
        $project = $this->project('Orca batch');
        $this->character->setActiveProject($project);

        $resource = $this->provider->provide(new Patch(), ['id' => $this->character->getId()->toRfc4122()]);

        self::assertSame($this->character->getId()->toRfc4122(), $resource->id);
        self::assertSame($project->getId()->toRfc4122(), $resource->activeProjectId);
        self::assertSame('Orca batch', $resource->activeProjectName);
    }

    public function testItReturnsNullIdAndNameWhenTheCharacterHasNoActiveProject(): void
    {
        $resource = $this->provider->provide(new Patch(), ['id' => $this->character->getId()->toRfc4122()]);

        self::assertSame($this->character->getId()->toRfc4122(), $resource->id);
        self::assertNull($resource->activeProjectId);
        self::assertNull($resource->activeProjectName);
    }

    public function testACharacterOfAnotherUserIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Patch(), ['id' => $this->characterOfAnotherUser->getId()->toRfc4122()]);
    }

    public function testAnUnknownCharacterIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Patch(), ['id' => self::UNKNOWN_CHARACTER_ID]);
    }

    public function testAnIdentifierThatIsNotAUuidIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Patch(), ['id' => 'not-a-uuid']);
    }

    private function characterOf(User $user, string $name): Character
    {
        $character = (new Character())->setName($name);
        $user->addCharacter($character);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v7());

        return $character;
    }

    private function project(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setName($name);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v7());

        return $project;
    }
}
