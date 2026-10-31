<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorDaysTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    #[DataProvider('daysOutsideOneToThreeThousandSixHundredFifty')]
    public function testDaysOutsideOneToThreeThousandSixHundredFiftyIsABadRequestWithoutAnyEsiRequest(string $days): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveContributedAgo('-2 days'), days: $days);

        try {
            $processor->process(null, new Post());
            $this->fail("days={$days} was accepted");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }

        $this->assertSame([], $this->characterRequests);
        $this->assertSame([], $this->requestedPaths());
        $this->assertSame([], $this->accessTokenRequests);
    }

    public static function daysOutsideOneToThreeThousandSixHundredFifty(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-7'];
        yield 'one more than ten years' => ['3651'];
        yield 'decimal' => ['7.5'];
        yield 'not a number' => ['abc'];
        yield 'number followed by text' => ['30days'];
    }

    public function testScanOfOneDayProposesAContributionOfTwelveHoursAgo(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveContributedAgo('-12 hours'), days: '1');

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 730, 9125.0]], $this->contributionSummaries($result->detectedSales));
    }

    public function testScanOfThreeThousandSixHundredFiftyDaysProposesAContributionOfNineYearsAgo(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveContributedAgo('-3300 days'), days: '3650');

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::PROJECT_NAME, 'Pilot One', 730, 9125.0]], $this->contributionSummaries($result->detectedSales));
    }

    private function tritaniumDriveContributedAgo(string $age): array
    {
        $contributedAt = new \DateTimeImmutable($age);

        return [
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Completed', $contributedAt),
            ]),
            ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 730)]),
            ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 730, $contributedAt),
        ];
    }
}
