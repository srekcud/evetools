<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Industry\Project\StationMigration\ProjectStationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationMigration;
use App\Service\Industry\Project\StationMigration\StationMigrationChange;
use App\Service\Industry\Project\StationMigration\StationMigrationReport;
use App\Service\Industry\Project\StationMigration\StationRevertReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:industry:migrate-to-station-model',
    description: 'Set the plan stations and the container locations of the projects created before the station model (dry run unless --apply)',
)]
final class MigrateToStationModelCommand extends Command
{
    public function __construct(private readonly StationMigration $migration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (without it, nothing is written)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Migrate one project (uuid)')
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'File receiving the change log, required with --apply')
            ->addOption('revert', null, InputOption::VALUE_REQUIRED, 'Undo the changes of a change log file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $logPath = $this->textOption($input, 'log');
        $revertPath = $this->textOption($input, 'revert');
        $projectId = $this->textOption($input, 'project');

        try {
            if (null !== $revertPath) {
                return $this->revert($output, $revertPath, $apply);
            }

            if ($apply && null === $logPath) {
                return $this->fail($output, '--apply needs --log=<file> to keep what changed and be able to revert it.');
            }
            if (!$apply && null !== $logPath) {
                return $this->fail($output, '--log is only written with --apply.');
            }

            $report = $this->migration->run($apply, $projectId, $logPath);
        } catch (\Exception $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln($apply ? 'Mode: applied' : 'Mode: dry run');
        $this->printReport($output, $report);

        return Command::SUCCESS;
    }

    private function revert(OutputInterface $output, string $revertPath, bool $apply): int
    {
        $report = $this->migration->revert($revertPath, $apply);
        $output->writeln($apply ? 'Mode: reverted' : 'Mode: revert dry run');
        $output->writeln(\sprintf('Reverted: %d', \count($report->reverted)));
        $this->printChanges($output, $report->reverted);
        $output->writeln(\sprintf('Changed since: %d', \count($report->changedSince)));
        $this->printChanges($output, $report->changedSince);

        return Command::SUCCESS;
    }

    /**
     * @param list<StationMigrationChange> $changes
     */
    private function printChanges(OutputInterface $output, array $changes): void
    {
        foreach ($changes as $change) {
            $output->writeln(\sprintf('  %s %s', $change->kind->value, $change->subject));
        }
    }

    private function printReport(OutputInterface $output, StationMigrationReport $report): void
    {
        foreach ($report->projects as $project) {
            $this->printProject($output, $project);
        }

        $unmigratedCounts = $report->unmigratedCountsByReason();
        $output->writeln(\sprintf('Projects: %d', \count($report->projects)));
        $output->writeln(\sprintf('Changes: %d', \count($report->changes())));
        $output->writeln(\sprintf('Not migrated: %d', array_sum($unmigratedCounts)));
        foreach ($unmigratedCounts as $reason => $count) {
            $output->writeln(\sprintf('  %s: %d', $reason, $count));
        }
    }

    private function printProject(OutputInterface $output, ProjectStationMigrationReport $project): void
    {
        $output->writeln(\sprintf('Project %s (%s)', $project->projectName ?? $project->projectId, $project->status));
        foreach ($project->changes as $change) {
            $output->writeln(\sprintf('  change %s %s -> %s', $change->kind->value, $change->subject, $change->value));
        }
        foreach ($project->unmigrated as $unmigrated) {
            $output->writeln(\sprintf(
                '  not migrated %s %s: %s%s',
                $unmigrated->kind->value,
                $unmigrated->subject,
                $unmigrated->reason->value,
                null === $unmigrated->detail ? '' : \sprintf(' (%s)', $unmigrated->detail),
            ));
        }
    }

    private function textOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln($message);

        return Command::FAILURE;
    }
}
