<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\UserSettings;

use ApiPlatform\Metadata\Get;
use App\ApiResource\UserSettings\UserSettingsResource;
use App\Entity\User;
use App\State\Provider\UserSettings\UserSettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UserSettingsProvider::class)]
#[Group('issue-880')]
final class UserSettingsProviderTest extends TestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1049588174021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int PREFERRED_STRUCTURE_ID = 1035466617946;
    private const int LISTED_STRUCTURE_ID = 1028858195912;
    private const int OTHER_LISTED_STRUCTURE_ID = 1022734985679;

    public function testEffectiveMarketStructureIsTheFirstEntryOfTheListWhenNoPreferredStructureIsSet(): void
    {
        $user = (new User())->setMarketStructures([
            ['id' => self::LISTED_STRUCTURE_ID, 'name' => 'Ashab - Market Fortizar'],
            ['id' => self::OTHER_LISTED_STRUCTURE_ID, 'name' => 'Amarr - Trade Hub'],
        ]);

        $resource = $this->provide($user);

        self::assertSame(self::LISTED_STRUCTURE_ID, $resource->effectiveMarketStructureId);
        self::assertSame('Ashab - Market Fortizar', $resource->effectiveMarketStructureName);
        self::assertNull($resource->preferredMarketStructureId);
    }

    public function testEffectiveMarketStructureIsThePreferredOneEvenWhenTheListIsNotEmpty(): void
    {
        $user = (new User())
            ->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID)
            ->setPreferredMarketStructureName('Perimeter - Tranquility Trading Tower')
            ->setMarketStructures([['id' => self::LISTED_STRUCTURE_ID, 'name' => 'Ashab - Market Fortizar']]);

        $resource = $this->provide($user);

        self::assertSame(self::PREFERRED_STRUCTURE_ID, $resource->effectiveMarketStructureId);
        self::assertSame('Perimeter - Tranquility Trading Tower', $resource->effectiveMarketStructureName);
    }

    public function testEffectiveMarketStructureIsTheDefaultStructureWhenTheUserHasNoMarketStructure(): void
    {
        $resource = $this->provide(new User());

        self::assertSame(self::DEFAULT_STRUCTURE_ID, $resource->effectiveMarketStructureId);
        self::assertSame(self::DEFAULT_STRUCTURE_NAME, $resource->effectiveMarketStructureName);
    }

    private function provide(User $user): UserSettingsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return (new UserSettingsProvider($security, self::DEFAULT_STRUCTURE_ID, self::DEFAULT_STRUCTURE_NAME))->provide(new Get());
    }
}
