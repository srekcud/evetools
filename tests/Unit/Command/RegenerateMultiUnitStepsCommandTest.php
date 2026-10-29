<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RegenerateMultiUnitStepsCommand;
use App\Enum\ProjectCorrectionStatus;
use App\Service\Industry\MultiUnitProjectStepsRegeneration;
use App\Service\Industry\ProjectCorrectionOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RegenerateMultiUnitStepsCommand::class)]
#[Group('issue-284')]
final class RegenerateMultiUnitStepsCommandTest extends TestCase
{
    private const string NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000004051';
    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000016673';
    private const string HYDROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000004246';
    private const string NITROGEN_FUEL_BLOCK_CHANGE = 'root jobs 1, runs 1, units 25 -> jobs 1, runs 25, units 1000; steps 1 -> 1; job matches 1 -> 1, purchases 1 -> 1';

    private array $runsOfTheRegeneration = [];

    public function testCommandIsNamedForTheDeploymentLog(): void
    {
        self::assertSame('app:industry:regenerate-multi-unit-steps', $this->command([])->getName());
    }

    public function testChangedProjectIsPrintedWithItsRootJobsStepsJobMatchesAndPurchasesBeforeAndAfterThenCounted(): void
    {
        $tester = new CommandTester($this->command([self::nitrogenFuelBlockChanged()]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([false], $this->runsOfTheRegeneration);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::NITROGEN_FUEL_BLOCK_PROJECT_ID.' Nitrogen Fuel Block (25 runs): '.self::NITROGEN_FUEL_BLOCK_CHANGE, $display);
        self::assertMatchesRegularExpression('/Status Count -+ -+ Changed 1 Unchanged 0 Skipped 0 Failed 0 (-+ -+ )?Total 1 /', $display);
        self::assertStringNotContainsString('Dry run', $display);
    }

    public function testDryRunPrintsTheSameLineAndSaysNothingWasSaved(): void
    {
        $tester = new CommandTester($this->command([self::nitrogenFuelBlockChanged()]));

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([true], $this->runsOfTheRegeneration);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::NITROGEN_FUEL_BLOCK_PROJECT_ID.' Nitrogen Fuel Block (25 runs): '.self::NITROGEN_FUEL_BLOCK_CHANGE, $display);
        self::assertStringContainsString('Dry run - no changes were saved', $display);
    }

    public function testSkippedProjectIsPrintedWithItsReasonAndDoesNotFailTheCommand(): void
    {
        $tester = new CommandTester($this->command([
            new ProjectCorrectionOutcome(self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', 25, ProjectCorrectionStatus::Skipped, reason: 'ME/TE would change on 4314 manufacturing job 0: 8/20 -> 10/20'),
            new ProjectCorrectionOutcome(self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 10, ProjectCorrectionStatus::Unchanged),
        ]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::NITROGEN_FUEL_BLOCK_PROJECT_ID.' Nitrogen Fuel Block (25 runs): skipped: ME/TE would change on 4314 manufacturing job 0: 8/20 -> 10/20', $display);
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (10 runs): unchanged', $display);
        self::assertMatchesRegularExpression('/Changed 0 Unchanged 1 Skipped 1 Failed 0 (-+ -+ )?Total 2 /', $display);
    }

    public function testFailedProjectIsPrintedWithItsReasonAndFailsTheCommand(): void
    {
        $tester = new CommandTester($this->command([
            new ProjectCorrectionOutcome(self::HYDROGEN_FUEL_BLOCK_PROJECT_ID, 'Hydrogen Fuel Block', 30, ProjectCorrectionStatus::Failed, reason: 'Types missing from the SDE: 17889'),
            self::nitrogenFuelBlockChanged(),
        ]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::HYDROGEN_FUEL_BLOCK_PROJECT_ID.' Hydrogen Fuel Block (30 runs): failed: Types missing from the SDE: 17889', $display);
        self::assertMatchesRegularExpression('/Changed 1 Unchanged 0 Skipped 0 Failed 1 (-+ -+ )?Total 2 /', $display);
    }

    public function testFailedProjectFailsTheDryRunToo(): void
    {
        $tester = new CommandTester($this->command([
            new ProjectCorrectionOutcome(self::HYDROGEN_FUEL_BLOCK_PROJECT_ID, 'Hydrogen Fuel Block', 30, ProjectCorrectionStatus::Failed, reason: 'Types missing from the SDE: 17889'),
        ]));

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));
    }

    public function testNoProjectToRegenerateSucceeds(): void
    {
        $tester = new CommandTester($this->command([]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No project to regenerate', self::normalized($tester->getDisplay()));
    }

    private function command(array $outcomes): RegenerateMultiUnitStepsCommand
    {
        $regeneration = $this->createStub(MultiUnitProjectStepsRegeneration::class);
        $regeneration->method('run')->willReturnCallback(function (bool $dryRun) use ($outcomes): array {
            $this->runsOfTheRegeneration[] = $dryRun;

            return $outcomes;
        });

        return new RegenerateMultiUnitStepsCommand($regeneration);
    }

    private static function nitrogenFuelBlockChanged(): ProjectCorrectionOutcome
    {
        return new ProjectCorrectionOutcome(
            self::NITROGEN_FUEL_BLOCK_PROJECT_ID,
            'Nitrogen Fuel Block',
            25,
            ProjectCorrectionStatus::Changed,
            change: self::NITROGEN_FUEL_BLOCK_CHANGE,
        );
    }

    private static function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
