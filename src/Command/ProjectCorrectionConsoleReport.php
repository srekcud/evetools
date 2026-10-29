<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ProjectCorrectionStatus;
use App\Service\Industry\ProjectCorrectionOutcome;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProjectCorrectionConsoleReport
{
    /**
     * @param list<ProjectCorrectionOutcome> $outcomes
     */
    public static function print(SymfonyStyle $io, array $outcomes, bool $dryRun, string $noProjectMessage): int
    {
        if ($outcomes === []) {
            $io->success($noProjectMessage);

            return Command::SUCCESS;
        }

        foreach ($outcomes as $outcome) {
            $io->writeln(\sprintf('%s %s (%d runs): %s', $outcome->projectId, $outcome->label, $outcome->runs, self::resultOf($outcome)));
        }

        $countOf = static fn (ProjectCorrectionStatus $status): int => \count(array_filter(
            $outcomes,
            static fn (ProjectCorrectionOutcome $outcome): bool => $outcome->status === $status,
        ));
        $io->table(['Status', 'Count'], [
            ['Changed', $countOf(ProjectCorrectionStatus::Changed)],
            ['Unchanged', $countOf(ProjectCorrectionStatus::Unchanged)],
            ['Skipped', $countOf(ProjectCorrectionStatus::Skipped)],
            ['Failed', $countOf(ProjectCorrectionStatus::Failed)],
            new TableSeparator(),
            ['Total', \count($outcomes)],
        ]);

        if ($dryRun) {
            $io->warning('Dry run - no changes were saved');
        }

        return $countOf(ProjectCorrectionStatus::Failed) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private static function resultOf(ProjectCorrectionOutcome $outcome): string
    {
        return match ($outcome->status) {
            ProjectCorrectionStatus::Changed => $outcome->change ?? throw new \LogicException('A changed project has no change description'),
            ProjectCorrectionStatus::Unchanged => 'unchanged',
            ProjectCorrectionStatus::Skipped => 'skipped: '.($outcome->reason ?? throw new \LogicException('A skipped project has no reason')),
            ProjectCorrectionStatus::Failed => 'failed: '.($outcome->reason ?? throw new \LogicException('A failed project has no reason')),
        };
    }
}
