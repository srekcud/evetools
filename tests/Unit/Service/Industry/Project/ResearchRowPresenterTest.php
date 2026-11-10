<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\ResearchJobEstimate;
use App\Industry\Application\ResearchSuggestion;
use App\Industry\Application\ResearchVerdict;
use App\Entity\CachedCharacterSkill;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ResearchJobDuration;
use App\Service\Industry\Project\ResearchRowPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchRowPresenter::class)]
#[Group('issue-410')]
final class ResearchRowPresenterTest extends TestCase
{
    private const int HOBGOBLIN_II = 2456;
    private const int WARRIOR_II = 2488;

    public function testKnownSuggestionIsPresentedWithAmountsRoundedToTwoDecimalsAndTheVerdictAsAString(): void
    {
        $suggestion = new ResearchSuggestion(
            self::HOBGOBLIN_II,
            0,
            1,
            10,
            new Isk(10_000.456),
            new Isk(14_500.004),
            -4_500.548,
            ResearchVerdict::NotWorth,
            [],
            $this->knownMeResearch(),
            $this->knownTeResearch(),
        );

        self::assertSame(
            $this->sorted([
                'typeId' => self::HOBGOBLIN_II,
                'typeName' => 'Hobgoblin II',
                'currentLevel' => 0,
                'targetLevel' => 1,
                'runs' => 10,
                'materialSaving' => 10_000.46,
                'researchCost' => 14_500.0,
                'net' => -4_500.55,
                'verdict' => 'not_worth',
                'missingData' => [],
                'meResearchDuration' => ['targetLevel' => 5, 'seconds' => 44_352, 'missingData' => []],
                'teResearchDuration' => ['targetLevel' => 4, 'seconds' => 26_545, 'missingData' => []],
            ]),
            $this->sorted(ResearchRowPresenter::of($suggestion, 'Hobgoblin II')),
        );
    }

    public function testUnknownSuggestionIsPresentedWithNullAmountsTheUnknownVerdictAndTheMissingData(): void
    {
        $suggestion = new ResearchSuggestion(
            self::WARRIOR_II,
            4,
            5,
            3,
            null,
            new Isk(232_000.0),
            null,
            ResearchVerdict::Unknown,
            [MissingData::marketPrice(self::WARRIOR_II), MissingData::costIndex()],
            $this->knownMeResearch(),
            $this->knownTeResearch(),
        );

        $row = ResearchRowPresenter::of($suggestion, 'Warrior II');

        self::assertSame(
            $this->sorted([
                'typeId' => self::WARRIOR_II,
                'typeName' => 'Warrior II',
                'currentLevel' => 4,
                'targetLevel' => 5,
                'runs' => 3,
                'materialSaving' => null,
                'researchCost' => 232_000.0,
                'net' => null,
                'verdict' => 'unknown',
                'missingData' => [
                    ['reason' => 'market_price', 'typeId' => self::WARRIOR_II],
                    ['reason' => 'cost_index', 'typeId' => null],
                ],
                'meResearchDuration' => ['targetLevel' => 5, 'seconds' => 44_352, 'missingData' => []],
                'teResearchDuration' => ['targetLevel' => 4, 'seconds' => 26_545, 'missingData' => []],
            ]),
            $this->sorted($row),
        );
        self::assertArrayHasKey('materialSaving', $row);
        self::assertArrayHasKey('net', $row);
    }

    #[Group('issue-475')]
    public function testKnownResearchDurationsAreSecondsWithTheirTargetLevelAndNoMissingData(): void
    {
        $row = ResearchRowPresenter::of($this->suggestionWith($this->knownMeResearch(), $this->knownTeResearch()), 'Hobgoblin II');

        self::assertSame(['targetLevel' => 5, 'seconds' => 44_352, 'missingData' => []], $row['meResearchDuration']);
        self::assertSame(['targetLevel' => 4, 'seconds' => 26_545, 'missingData' => []], $row['teResearchDuration']);
    }

    #[Group('issue-475')]
    public function testUnknownResearchDurationHasNullSecondsAndItsReasonsWithTheirTypeId(): void
    {
        $row = ResearchRowPresenter::of(
            $this->suggestionWith(
                new ResearchJobEstimate(1, ResearchJobDuration::unknown([MissingData::researcher()])),
                new ResearchJobEstimate(6, ResearchJobDuration::unknown([
                    MissingData::researchBaseTime(self::HOBGOBLIN_II),
                    MissingData::researchSkill(CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY),
                ])),
            ),
            'Hobgoblin II',
        );

        self::assertSame(
            ['targetLevel' => 1, 'seconds' => null, 'missingData' => [['reason' => 'researcher', 'typeId' => null]]],
            $row['meResearchDuration'],
        );
        self::assertSame(
            [
                'targetLevel' => 6,
                'seconds' => null,
                'missingData' => [
                    ['reason' => 'research_base_time', 'typeId' => self::HOBGOBLIN_II],
                    ['reason' => 'research_skill', 'typeId' => CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY],
                ],
            ],
            $row['teResearchDuration'],
        );
    }

    #[Group('issue-475')]
    public function testNoTeResearchAtTe20IsAnExplicitNullKeyWhileTheMeDurationRemains(): void
    {
        $row = ResearchRowPresenter::of($this->suggestionWith($this->knownMeResearch(), null), 'Hobgoblin II');

        self::assertArrayHasKey('teResearchDuration', $row);
        self::assertNull($row['teResearchDuration']);
        self::assertSame(['targetLevel' => 5, 'seconds' => 44_352, 'missingData' => []], $row['meResearchDuration']);
    }

    private function suggestionWith(ResearchJobEstimate $meResearch, ?ResearchJobEstimate $teResearch): ResearchSuggestion
    {
        return new ResearchSuggestion(self::HOBGOBLIN_II, 4, 5, 10, new Isk(10_000.0), new Isk(14_500.0), -4_500.0, ResearchVerdict::NotWorth, [], $meResearch, $teResearch);
    }

    private function knownMeResearch(): ResearchJobEstimate
    {
        return new ResearchJobEstimate(5, ResearchJobDuration::of(ActivityKind::ResearchMaterial, 2_100, 5, 5, 4, Multiplier::one()));
    }

    private function knownTeResearch(): ResearchJobEstimate
    {
        return new ResearchJobEstimate(4, ResearchJobDuration::of(ActivityKind::ResearchTime, 2_800, 4, 4, 4, Multiplier::one()));
    }

    private function sorted(array $row): array
    {
        ksort($row);

        return $row;
    }
}
