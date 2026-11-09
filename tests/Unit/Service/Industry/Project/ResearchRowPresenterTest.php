<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\ResearchSuggestion;
use App\Industry\Application\ResearchVerdict;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
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
            ]),
            $this->sorted($row),
        );
        self::assertArrayHasKey('materialSaving', $row);
        self::assertArrayHasKey('net', $row);
    }

    private function sorted(array $row): array
    {
        ksort($row);

        return $row;
    }
}
