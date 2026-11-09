<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Skills;

use App\Entity\CachedCharacterAttributes;
use App\Entity\Character;
use App\Entity\Sde\DgmTypeAttribute;
use App\Enum\SkillTrainingUnknownReason;
use App\Repository\CachedCharacterAttributesRepository;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Service\Industry\Skills\SkillTrainingEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillTrainingEstimator::class)]
#[Group('issue-359')]
final class SkillTrainingEstimatorTest extends TestCase
{
    private const int INDUSTRY = 3380;
    private const int ADVANCED_INDUSTRY = 3388;
    private const int RANK_ATTRIBUTE = 275;
    private const int PRIMARY_ATTRIBUTE = 180;
    private const int SECONDARY_ATTRIBUTE = 181;
    private const int INTELLIGENCE = 165;
    private const int MEMORY = 166;
    private const int PERCEPTION = 167;

    private Character $character;
    private ?CachedCharacterAttributes $storedAttributes;
    private array $sdeRowsBySkillId = [];

    protected function setUp(): void
    {
        $this->character = (new Character())->setName('Trainee')->setEveCharacterId(2_112_000_360);
        $this->storedAttributes = (new CachedCharacterAttributes())
            ->setCharacter($this->character)
            ->setAttributes(20, 25, 17, 17, 17);
        $this->sdeRowsBySkillId = [
            self::INDUSTRY => $this->sdeRows(1.0, self::MEMORY, self::INTELLIGENCE),
            self::ADVANCED_INDUSTRY => $this->sdeRows(3.0, self::MEMORY, self::INTELLIGENCE),
        ];
    }

    public function testComputesTheDurationFromTheSdeProfileAndTheCharacterAttributes(): void
    {
        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame([63_866, null], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testUsesTheRankOfTheSkill(): void
    {
        $estimate = $this->estimator()->nextLevel($this->character, self::ADVANCED_INDUSTRY, 0);

        $this->assertSame([1_286, null], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testReadsTheSdeValuesWhateverTheirStorageType(): void
    {
        $this->sdeRowsBySkillId[self::INDUSTRY] = [
            $this->sdeRow(self::RANK_ATTRIBUTE, 1),
            $this->sdeRow(self::PRIMARY_ATTRIBUTE, self::MEMORY),
            $this->sdeRow(self::SECONDARY_ATTRIBUTE, self::INTELLIGENCE),
        ];

        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame(63_866, $estimate->seconds);
    }

    public function testAnAttributeOtherThanMemoryAndIntelligenceFollowsTheSdeChoice(): void
    {
        $this->sdeRowsBySkillId[self::INDUSTRY] = $this->sdeRows(1.0, self::PERCEPTION, self::INTELLIGENCE);

        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame(82_789, $estimate->seconds);
    }

    public function testTheDurationIsUnknownWhenTheCharacterHasNoAttributes(): void
    {
        $this->storedAttributes = null;

        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame([null, SkillTrainingUnknownReason::AttributesNotSynchronized], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testTheDurationIsUnknownForASkillAbsentFromTheSde(): void
    {
        $estimate = $this->estimator()->nextLevel($this->character, 999_999, 3);

        $this->assertSame([null, SkillTrainingUnknownReason::SkillNotInSde], [$estimate->seconds, $estimate->unknownReason]);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function incompleteProfiles(): iterable
    {
        yield 'no rank' => [[self::PRIMARY_ATTRIBUTE, self::SECONDARY_ATTRIBUTE]];
        yield 'no primary attribute' => [[self::RANK_ATTRIBUTE, self::SECONDARY_ATTRIBUTE]];
        yield 'no secondary attribute' => [[self::RANK_ATTRIBUTE, self::PRIMARY_ATTRIBUTE]];
    }

    #[DataProvider('incompleteProfiles')]
    public function testTheDurationIsUnknownWhenTheSdeProfileIsIncomplete(array $keptAttributeIds): void
    {
        $this->sdeRowsBySkillId[self::INDUSTRY] = array_values(array_filter(
            $this->sdeRows(1.0, self::MEMORY, self::INTELLIGENCE),
            static fn (DgmTypeAttribute $row): bool => in_array($row->getAttributeId(), $keptAttributeIds, true),
        ));

        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame([null, SkillTrainingUnknownReason::SkillNotInSde], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testTheDurationIsUnknownWhenTheSdePointsToAnAttributeThatIsNotACharacterAttribute(): void
    {
        $this->sdeRowsBySkillId[self::INDUSTRY] = $this->sdeRows(1.0, 999, self::INTELLIGENCE);

        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 3);

        $this->assertSame([null, SkillTrainingUnknownReason::SkillNotInSde], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testThereIsNoNextLevelAtLevelFiveWhateverTheOtherData(): void
    {
        $estimate = $this->estimator()->nextLevel($this->character, self::INDUSTRY, 5);

        $this->assertSame([null, SkillTrainingUnknownReason::MaxLevelReached], [$estimate->seconds, $estimate->unknownReason]);
    }

    public function testMaxLevelWinsOverMissingAttributes(): void
    {
        $this->storedAttributes = null;

        $estimate = $this->estimator()->nextLevel($this->character, 999_999, 5);

        $this->assertSame(SkillTrainingUnknownReason::MaxLevelReached, $estimate->unknownReason);
    }

    public function testASkillAbsentFromTheSdeWinsOverMissingAttributes(): void
    {
        $this->storedAttributes = null;

        $estimate = $this->estimator()->nextLevel($this->character, 999_999, 3);

        $this->assertSame(SkillTrainingUnknownReason::SkillNotInSde, $estimate->unknownReason);
    }

    private function sdeRows(float $rank, int $primaryAttributeId, int $secondaryAttributeId): array
    {
        return [
            $this->sdeRow(self::RANK_ATTRIBUTE, null, $rank),
            $this->sdeRow(self::PRIMARY_ATTRIBUTE, null, (float) $primaryAttributeId),
            $this->sdeRow(self::SECONDARY_ATTRIBUTE, null, (float) $secondaryAttributeId),
        ];
    }

    private function sdeRow(int $attributeId, ?int $valueInt = null, ?float $valueFloat = null): DgmTypeAttribute
    {
        return (new DgmTypeAttribute())
            ->setAttributeId($attributeId)
            ->setValueInt($valueInt)
            ->setValueFloat($valueFloat);
    }

    private function estimator(): SkillTrainingEstimator
    {
        $typeAttributes = $this->createStub(DgmTypeAttributeRepository::class);
        $typeAttributes->method('findByTypeId')->willReturnCallback(
            fn (int $typeId): array => $this->sdeRowsBySkillId[$typeId] ?? [],
        );

        $attributes = $this->createStub(CachedCharacterAttributesRepository::class);
        $attributes->method('findOneBy')->willReturnCallback(fn (): ?CachedCharacterAttributes => $this->storedAttributes);

        return new SkillTrainingEstimator($typeAttributes, $attributes);
    }
}
