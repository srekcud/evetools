<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Me;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Me\CharacterResource;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Service\ESI\TokenManager;
use App\State\Provider\Me\CharacterCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CharacterCollectionProvider::class)]
#[Group('issue-1249')]
final class CharacterCollectionProviderActiveProjectTest extends TestCase
{
    public function testEachCharacterCarriesTheIdAndNameOfItsActiveProjectOrNullWhenItHasNone(): void
    {
        $user = new User();
        $dedicated = $this->characterOf($user, 'Aria Solberg');
        $free = $this->characterOf($user, 'Aria Alt');
        $user->setMainCharacter($dedicated);
        $project = (new IndustryProject())->setName('Orca batch');
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v7());
        $dedicated->setActiveProject($project);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $provider = new CharacterCollectionProvider($security, $this->createStub(TokenManager::class));

        $resources = $provider->provide(new GetCollection());

        $byName = [];
        foreach ($resources as $resource) {
            $byName[$resource->name] = $resource;
        }
        self::assertSame($project->getId()->toRfc4122(), $byName['Aria Solberg']->activeProjectId);
        self::assertSame('Orca batch', $byName['Aria Solberg']->activeProjectName);
        self::assertNull($byName['Aria Alt']->activeProjectId);
        self::assertNull($byName['Aria Alt']->activeProjectName);
        self::assertContainsOnlyInstancesOf(CharacterResource::class, $resources);
    }

    private function characterOf(User $user, string $name): Character
    {
        $character = (new Character())->setName($name)->setEveCharacterId(2_112_249_000 + \strlen($name))->setCorporationId(98_249_001)->setCorporationName('Test Corp');
        $user->addCharacter($character);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v7());

        return $character;
    }
}
