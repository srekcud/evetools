<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Pve;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Pve\TypeResource;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\UserPveSettingsRepository;
use App\State\Provider\Pve\SettingsProvider;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(SettingsProvider::class)]
class SettingsProviderTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int TRITANIUM_TYPE_ID = 34;
    private const string TRITANIUM_NAME = 'Tritanium';

    public function testAmmoTypeMissingFromSdeHasUnknownTypeName(): void
    {
        $settings = (new UserPveSettings())->setAmmoTypeIds([self::TRITANIUM_TYPE_ID, self::ROBOTICS_TYPE_ID]);

        $resource = $this->providerFor($settings)->provide(new Get());

        $this->assertSame(
            [[self::TRITANIUM_TYPE_ID, self::TRITANIUM_NAME], [self::ROBOTICS_TYPE_ID, null]],
            $this->typeIdsAndNames($resource->ammoTypes),
        );
        $this->assertSame([], $resource->lootTypes);
    }

    public function testLootTypeMissingFromSdeHasUnknownTypeName(): void
    {
        $settings = (new UserPveSettings())->setLootTypeIds([self::ROBOTICS_TYPE_ID]);

        $resource = $this->providerFor($settings)->provide(new Get());

        $this->assertSame([[self::ROBOTICS_TYPE_ID, null]], $this->typeIdsAndNames($resource->lootTypes));
        $this->assertSame([], $resource->ammoTypes);
    }

    private function providerFor(UserPveSettings $settings): SettingsProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(User::class));
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        return new SettingsProvider(
            $security,
            $settingsRepository,
            $this->sdeTypeRepositoryKnowing([self::TRITANIUM_TYPE_ID => self::TRITANIUM_NAME]),
        );
    }

    private function typeIdsAndNames(array $typeResources): array
    {
        return array_map(
            static fn (TypeResource $typeResource): array => [$typeResource->typeId, $typeResource->typeName],
            $typeResources,
        );
    }
}
