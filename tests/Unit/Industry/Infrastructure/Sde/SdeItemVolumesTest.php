<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Entity\Sde\InvType;
use App\Industry\Infrastructure\Sde\SdeItemVolumes;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeItemVolumes::class)]
final class SdeItemVolumesTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const float TRITANIUM_CUBIC_METERS = 0.01;
    private const int HURRICANE = 24702;
    private const float HURRICANE_CUBIC_METERS = 15000.0;
    private const int TYPE_WITHOUT_VOLUME = 11399;
    private const int TYPE_ABSENT_FROM_THE_SDE = 999999;

    public function testVolumeOfATypeIsItsCubicMetersFromTheSde(): void
    {
        $volumes = $this->itemVolumes();

        $this->assertSame(self::TRITANIUM_CUBIC_METERS, $volumes->volumeOf(self::TRITANIUM));
        $this->assertSame(self::HURRICANE_CUBIC_METERS, $volumes->volumeOf(self::HURRICANE));
    }

    public function testTypeWithoutVolumeInTheSdeHasNoVolume(): void
    {
        $this->assertNull($this->itemVolumes()->volumeOf(self::TYPE_WITHOUT_VOLUME));
    }

    public function testTypeAbsentFromTheSdeHasNoVolume(): void
    {
        $this->assertNull($this->itemVolumes()->volumeOf(self::TYPE_ABSENT_FROM_THE_SDE));
    }

    private function itemVolumes(): SdeItemVolumes
    {
        $types = [
            self::TRITANIUM => (new InvType())->setTypeId(self::TRITANIUM)->setTypeName('Tritanium')->setVolume(self::TRITANIUM_CUBIC_METERS),
            self::HURRICANE => (new InvType())->setTypeId(self::HURRICANE)->setTypeName('Hurricane')->setVolume(self::HURRICANE_CUBIC_METERS),
            self::TYPE_WITHOUT_VOLUME => (new InvType())->setTypeId(self::TYPE_WITHOUT_VOLUME)->setTypeName('Morphite')->setVolume(null),
        ];

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $types[$typeId] ?? null);

        return new SdeItemVolumes($repository);
    }
}
