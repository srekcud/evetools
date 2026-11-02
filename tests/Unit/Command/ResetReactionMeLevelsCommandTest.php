<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ResetReactionMeLevelsCommand;
use App\Enum\ProjectCorrectionStatus;
use App\Service\Industry\ProjectCorrectionOutcome;
use App\Service\Industry\ReactionStepMeLevelReset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ResetReactionMeLevelsCommand::class)]
#[Group('issue-190')]
final class ResetReactionMeLevelsCommandTest extends TestCase
{
    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000016673';
    private const string SECOND_FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-190000116673';
    private const string FERNITE_CARBIDE_AT_ME_TEN_RESET = '1 reaction steps ME 10 -> 0, 2 steps recalculated, steps 3 -> 3';

    private array $runsOfTheReset = [];

    public function testCommandIsNamedAndDescribedForTheDeploymentLog(): void
    {
        $command = $this->command([]);

        self::assertSame(
            ['app:industry:reset-reaction-me-levels', 'Reset to 0 the material efficiency of the reaction steps of the active projects and recalculate their steps'],
            [$command->getName(), $command->getDescription()],
        );
    }

    public function testDryRunIsTheOnlyOption(): void
    {
        $options = $this->command([])->getDefinition()->getOptions();

        self::assertSame(['dry-run'], array_keys($options));
        self::assertSame(
            [false, 'Only show what would be done, do not save'],
            [$options['dry-run']->acceptValue(), $options['dry-run']->getDescription()],
        );
    }

    public function testResetProjectIsPrintedWithItsResetReactionStepsRecalculatedStepsAndStepsBeforeAndAfterThenCounted(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideReset(self::FERNITE_CARBIDE_PROJECT_ID)]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([false], $this->runsOfTheReset);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (20 runs): '.self::FERNITE_CARBIDE_AT_ME_TEN_RESET, $display);
        self::assertMatchesRegularExpression('/Status Count -+ -+ Changed 1 Unchanged 0 Skipped 0 Failed 0 (-+ -+ )?Total 1 /', $display);
        self::assertStringNotContainsString('Dry run', $display);
    }

    public function testDryRunPrintsTheSameLineAndSaysNothingWasSaved(): void
    {
        $tester = new CommandTester($this->command([self::ferniteCarbideReset(self::FERNITE_CARBIDE_PROJECT_ID)]));

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([true], $this->runsOfTheReset);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (20 runs): '.self::FERNITE_CARBIDE_AT_ME_TEN_RESET, $display);
        self::assertStringContainsString('Dry run - no changes were saved', $display);
    }

    public function testNoProjectToResetSucceeds(): void
    {
        $tester = new CommandTester($this->command([]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No project to reset', self::normalized($tester->getDisplay()));
    }

    public function testFailedProjectIsPrintedWithItsReasonAndFailsTheCommand(): void
    {
        $tester = new CommandTester($this->command(self::failedThenResetProjects()));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = self::normalized($tester->getDisplay());
        self::assertStringContainsString(self::FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (20 runs): failed: Product of blueprint 999999999 missing from the SDE', $display);
        self::assertStringContainsString(self::SECOND_FERNITE_CARBIDE_PROJECT_ID.' Fernite Carbide (20 runs): '.self::FERNITE_CARBIDE_AT_ME_TEN_RESET, $display);
        self::assertMatchesRegularExpression('/Changed 1 Unchanged 0 Skipped 0 Failed 1 (-+ -+ )?Total 2 /', $display);
    }

    public function testFailedProjectFailsTheDryRunToo(): void
    {
        $tester = new CommandTester($this->command(self::failedThenResetProjects()));

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Dry run - no changes were saved', self::normalized($tester->getDisplay()));
    }

    private function command(array $outcomes): ResetReactionMeLevelsCommand
    {
        $reset = $this->createStub(ReactionStepMeLevelReset::class);
        $reset->method('run')->willReturnCallback(function (bool $dryRun) use ($outcomes): array {
            $this->runsOfTheReset[] = $dryRun;

            return $outcomes;
        });

        return new ResetReactionMeLevelsCommand($reset);
    }

    private static function failedThenResetProjects(): array
    {
        return [
            new ProjectCorrectionOutcome(self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', 20, ProjectCorrectionStatus::Failed, reason: 'Product of blueprint 999999999 missing from the SDE'),
            self::ferniteCarbideReset(self::SECOND_FERNITE_CARBIDE_PROJECT_ID),
        ];
    }

    private static function ferniteCarbideReset(string $projectId): ProjectCorrectionOutcome
    {
        return new ProjectCorrectionOutcome($projectId, 'Fernite Carbide', 20, ProjectCorrectionStatus::Changed, change: self::FERNITE_CARBIDE_AT_ME_TEN_RESET);
    }

    private static function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
