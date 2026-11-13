<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Entity\SdeBuildState;
use App\Enum\AdminHealthScope;
use App\Enum\SdeBuildStatus;
use App\Message\CheckSdeBuild;
use App\Repository\SdeBuildStateRepository;
use App\Repository\UserRepository;
use App\Security\AdminChecker;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sde\SdeBuild;
use App\Service\Sde\SdeBuildStatusResolver;
use App\Service\Sde\SdeLatestBuildFetcher;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckSdeBuildHandler
{
    private const string SYNC_KEY = 'sde-build';
    private const string ADMIN_ROUTE = '/admin';
    private const string NOTIFICATION_TITLE = 'Newer SDE build available';
    private const string RELEASE_DATE_DISPLAY_FORMAT = 'Y-m-d';

    public function __construct(
        private SdeLatestBuildFetcher $latestBuildFetcher,
        private SdeBuildStateRepository $stateRepository,
        private SdeBuildStatusResolver $statusResolver,
        private UserRepository $userRepository,
        private AdminChecker $adminChecker,
        private NotificationDispatcher $notificationDispatcher,
        private SyncTracker $syncTracker,
        private MercurePublisherService $mercurePublisher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckSdeBuild $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $latestBuild = $this->latestBuildFetcher->fetch();
            $state = $this->stateRepository->current();
            $this->recordLatestBuild($state, $latestBuild);

            $status = $this->statusResolver->resolve($state);
            $notifiedAdmins = $this->notifyAdminsOnce($state, $status, $latestBuild);

            $this->syncTracker->complete(self::SYNC_KEY, sprintf(
                'Latest build %d, status %s, %d admin(s) notified',
                $latestBuild->buildNumber,
                $status->value,
                $notifiedAdmins,
            ));
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        } finally {
            $this->mercurePublisher->publishAdminHealth(AdminHealthScope::SdeBuild);
        }
    }

    private function recordLatestBuild(SdeBuildState $state, SdeBuild $latestBuild): void
    {
        $state
            ->setLatestBuildNumber($latestBuild->buildNumber)
            ->setLatestReleaseDate($latestBuild->releaseDate)
            ->setLatestCheckedAt($this->clock->now());
        $this->stateRepository->save($state);
    }

    private function notifyAdminsOnce(SdeBuildState $state, SdeBuildStatus $status, SdeBuild $latestBuild): int
    {
        $needsAttention = $status === SdeBuildStatus::Outdated || $status === SdeBuildStatus::NotRecorded;
        if (!$needsAttention || $state->getNotifiedBuildNumber() === $latestBuild->buildNumber) {
            return 0;
        }

        $admins = $this->userRepository->findAdminsByMainCharacterEveIds($this->adminChecker->listedEveCharacterIds());
        foreach ($admins as $admin) {
            $this->notificationDispatcher->dispatch(
                $admin,
                Notification::CATEGORY_SDE_BUILD,
                Notification::LEVEL_WARNING,
                self::NOTIFICATION_TITLE,
                $this->notificationMessage($state, $latestBuild),
                [
                    'importedBuildNumber' => $state->getImportedBuildNumber(),
                    'latestBuildNumber' => $latestBuild->buildNumber,
                    'latestReleaseDate' => $latestBuild->releaseDate->format(\DateTimeInterface::ATOM),
                ],
                self::ADMIN_ROUTE,
            );
        }

        $state->setNotifiedBuildNumber($latestBuild->buildNumber);
        $this->stateRepository->save($state);

        return count($admins);
    }

    private function notificationMessage(SdeBuildState $state, SdeBuild $latestBuild): string
    {
        $importedBuildNumber = $state->getImportedBuildNumber();
        $imported = $importedBuildNumber === null
            ? 'No imported build is recorded'
            : sprintf('The imported build is %d', $importedBuildNumber);

        return sprintf(
            'CCP published SDE build %d (released %s). %s. Run make sde-import to update.',
            $latestBuild->buildNumber,
            $latestBuild->releaseDate->format(self::RELEASE_DATE_DISPLAY_FORMAT),
            $imported,
        );
    }
}
