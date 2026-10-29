<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RecalculateReactionSellPricesCommand;
use App\Enum\ProjectCorrectionStatus;
use App\Service\Industry\ProjectCorrectionOutcome;
use App\Service\Industry\ReactionProjectSellPriceRecalculation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RecalculateReactionSellPricesCommand::class)]
#[Group('issue-278')]
final class RecalculateReactionSellPricesCommandTest extends TestCase
{
    private const string CREATED_BEFORE = '2026-11-01T00:00:00+00:00';
    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000016673';
    private const string CERAMIC_POWDER_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000016660';
    private const string ROBOTICS_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000009848';
    private const string SECOND_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-278000026673';

    private array $runsOfTheRecalculation = [];

    public function testCommandIsNamedForTheDeploymentLog(): void
    {
        self::assertSame('app:industry:recalculate-reaction-sell-prices', $this->command([])->getName());
    }

    public function testChangedProjectIsPrintedWithItsSellPriceAndTaxBeforeAndAfterThenCounted(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideChanged()]));

        $exitCode = $tester->execute(['--created-before' => self::CREATED_BEFORE]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([[self::CREATED_BEFORE, false]], $this->runsOfTheRecalculation);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (8 runs): sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00', $display);
        self::assertMatchesRegularExpression('/Status Count -+ -+ Changed 1 Unchanged 0 Skipped 0 Failed 0 (-+ -+ )?Total 1 /', $display);
        self::assertStringNotContainsString('Dry run', $display);
    }

    public function testDryRunPrintsTheSameLineAndSaysNothingWasSaved(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideChanged()]));

        $exitCode = $tester->execute(['--created-before' => self::CREATED_BEFORE, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([[self::CREATED_BEFORE, true]], $this->runsOfTheRecalculation);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (8 runs): sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00', $display);
        self::assertStringContainsString('Dry run - no changes were saved', $display);
    }

    public function testFailedProjectsArePrintedWithTheirReasonAndFailTheCommand(): void
    {
        $tester = new CommandTester($this->command([
            new ProjectCorrectionOutcome(self::CERAMIC_POWDER_PROJECT_ID, 'Ceramic Powder', 12, ProjectCorrectionStatus::Failed, reason: 'No Jita nor structure sell price for type ID 16660'),
            new ProjectCorrectionOutcome(self::ROBOTICS_PROJECT_ID, 'Robotics', 6, ProjectCorrectionStatus::Failed, reason: 'No blueprint or reaction found for product type ID 9848'),
            self::ferniteCarbideChanged(),
            new ProjectCorrectionOutcome(self::SECOND_FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 4, ProjectCorrectionStatus::Unchanged),
        ]));

        $exitCode = $tester->execute(['--created-before' => self::CREATED_BEFORE]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::CERAMIC_POWDER_PROJECT_ID.' Ceramic Powder (12 runs): failed: No Jita nor structure sell price for type ID 16660', $display);
        self::assertStringContainsString(self::ROBOTICS_PROJECT_ID.' Robotics (6 runs): failed: No blueprint or reaction found for product type ID 9848', $display);
        self::assertStringContainsString(self::SECOND_FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (4 runs): unchanged', $display);
        self::assertMatchesRegularExpression('/Changed 1 Unchanged 1 Skipped 0 Failed 2 (-+ -+ )?Total 4 /', $display);
    }

    public function testFailedProjectFailsTheDryRunToo(): void
    {
        $tester = new CommandTester($this->command([
            new ProjectCorrectionOutcome(self::CERAMIC_POWDER_PROJECT_ID, 'Ceramic Powder', 12, ProjectCorrectionStatus::Failed, reason: 'No Jita nor structure sell price for type ID 16660'),
        ]));

        self::assertSame(Command::FAILURE, $tester->execute(['--created-before' => self::CREATED_BEFORE, '--dry-run' => true]));
    }

    public function testMissingCreatedBeforeOptionFailsWithoutRecalculatingAnything(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideChanged()]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $this->runsOfTheRecalculation);
        self::assertStringContainsString(
            'The --created-before option is required: pass the deployment time of the release that fixed the reaction sell price',
            self::normalized($tester->getDisplay()),
        );
    }

    public function testUnreadableCreatedBeforeOptionFailsWithoutRecalculatingAnything(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideChanged()]));

        $exitCode = $tester->execute(['--created-before' => 'not-a-date']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame([], $this->runsOfTheRecalculation);
        self::assertStringContainsString('Invalid --created-before value: not-a-date', self::normalized($tester->getDisplay()));
    }

    public function testNoProjectToRecalculateSucceeds(): void
    {
        $tester = new CommandTester($this->command([]));

        $exitCode = $tester->execute(['--created-before' => self::CREATED_BEFORE]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No project to recalculate', self::normalized($tester->getDisplay()));
    }

    private function command(array $outcomes): RecalculateReactionSellPricesCommand
    {
        $recalculation = $this->createStub(ReactionProjectSellPriceRecalculation::class);
        $recalculation->method('run')->willReturnCallback(function (\DateTimeImmutable $createdBefore, bool $dryRun) use ($outcomes): array {
            $this->runsOfTheRecalculation[] = [$createdBefore->format(\DateTimeInterface::ATOM), $dryRun];

            return $outcomes;
        });

        return new RecalculateReactionSellPricesCommand($recalculation);
    }

    private static function ferniteCarbideChanged(): ProjectCorrectionOutcome
    {
        return new ProjectCorrectionOutcome(
            self::FERNITE_CARBIDE_PROJECT_ID,
            'Fernite Carbide',
            8,
            ProjectCorrectionStatus::Changed,
            change: 'sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00',
        );
    }

    private static function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
