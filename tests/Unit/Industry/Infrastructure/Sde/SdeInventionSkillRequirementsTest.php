<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Entity\Sde\IndustryActivitySkill;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Application\InventionSkillRequirement;
use App\Industry\Infrastructure\Sde\SdeInventionSkillRequirements;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeInventionSkillRequirements::class)]
final class SdeInventionSkillRequirementsTest extends TestCase
{
    private const int INVENTION_ACTIVITY = 8;
    private const int SCIENCE_GROUP = 270;
    private const int PRODUCTION_GROUP = 268;
    private const int T1_BLUEPRINT = 20445;
    private const int AMARR_ENCRYPTION_METHODS = 23087;
    private const int HIGH_ENERGY_PHYSICS = 11433;
    private const int LASER_PHYSICS = 11442;
    private const int PLASMA_PHYSICS = 11441;
    private const int CAPITAL_SHIP_CONSTRUCTION = 22242;

    /** @var list<int> */
    private array $requiredSkillTypeIds = [];

    public function testSplitsTheScienceSkillsFromTheEncryptionSkillOfTheRecipe(): void
    {
        $this->requiredSkillTypeIds = [self::AMARR_ENCRYPTION_METHODS, self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS];

        $requirement = $this->requirements()->of(self::T1_BLUEPRINT);

        $this->assertEquals(new InventionSkillRequirement([self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS], self::AMARR_ENCRYPTION_METHODS), $requirement);
    }

    public function testIgnoresTheRequiredSkillsThatAreNotSciencesNorEncryption(): void
    {
        $this->requiredSkillTypeIds = [self::CAPITAL_SHIP_CONSTRUCTION, self::AMARR_ENCRYPTION_METHODS, self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS];

        $requirement = $this->requirements()->of(self::T1_BLUEPRINT);

        $this->assertSame([self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS], $requirement->scienceSkillTypeIds);
    }

    public function testARecipeWithTwoSciencesAndNoEncryptionHasNoEncryptionSkill(): void
    {
        $this->requiredSkillTypeIds = [self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS];

        $requirement = $this->requirements()->of(self::T1_BLUEPRINT);

        $this->assertNull($requirement->encryptionSkillTypeId);
        $this->assertCount(2, $requirement->scienceSkillTypeIds);
    }

    public function testMoreThanTwoSciencesIsAnInconsistentSde(): void
    {
        $this->requiredSkillTypeIds = [self::HIGH_ENERGY_PHYSICS, self::LASER_PHYSICS, self::PLASMA_PHYSICS];

        $this->expectException(\UnexpectedValueException::class);

        $this->requirements()->of(self::T1_BLUEPRINT);
    }

    public function testMoreThanOneEncryptionSkillIsAnInconsistentSde(): void
    {
        $this->requiredSkillTypeIds = [self::AMARR_ENCRYPTION_METHODS, 21790, self::LASER_PHYSICS];

        $this->expectException(\UnexpectedValueException::class);

        $this->requirements()->of(self::T1_BLUEPRINT);
    }

    private function requirements(): SdeInventionSkillRequirements
    {
        $skills = $this->createStub(IndustryActivitySkillRepository::class);
        $skills->method('findByBlueprintAndActivity')->willReturnCallback(
            fn (int $blueprintTypeId, int $activityId): array => self::T1_BLUEPRINT === $blueprintTypeId && self::INVENTION_ACTIVITY === $activityId
                ? array_map(
                    static fn (int $skillTypeId): IndustryActivitySkill => (new IndustryActivitySkill())->setTypeId(self::T1_BLUEPRINT)->setActivityId(self::INVENTION_ACTIVITY)->setSkillId($skillTypeId)->setLevel(1),
                    $this->requiredSkillTypeIds,
                )
                : [],
        );
        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(
                static fn (int $typeId): InvType => (new InvType())
                    ->setTypeId($typeId)
                    ->setGroup((new InvGroup())->setGroupId(self::CAPITAL_SHIP_CONSTRUCTION === $typeId ? self::PRODUCTION_GROUP : self::SCIENCE_GROUP)),
                $typeIds,
            )),
        );

        return new SdeInventionSkillRequirements($skills, $types);
    }
}
