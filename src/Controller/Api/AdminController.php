<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Message\TriggerAssetsSync;
use App\Message\TriggerJitaMarketSync;
use App\Message\TriggerStructureMarketSync;
use App\Message\TriggerPveSync;
use App\Message\TriggerAnsiblexSync;
use App\Message\SyncIndustryJobs;
use App\Service\Admin\AdminService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Route('/api/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminService $adminService,
        private readonly MessageBusInterface $messageBus,
        private readonly Connection $connection,
        private readonly KernelInterface $kernel,
        private readonly array $adminCharacterNames,
    ) {
    }

    private function checkAdminAccess(): ?JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $mainChar = $user->getMainCharacter();
        if (!$mainChar) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Case-insensitive comparison
        $mainCharName = strtolower($mainChar->getName());
        $isAdmin = false;
        foreach ($this->adminCharacterNames as $adminName) {
            if (strtolower($adminName) === $mainCharName) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    #[Route('/access', name: 'api_admin_access', methods: ['GET'])]
    public function checkAccess(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse([
                'hasAccess' => false,
                'characterName' => null,
            ]);
        }

        $mainChar = $user->getMainCharacter();
        $hasAccess = false;

        if ($mainChar) {
            $mainCharName = strtolower($mainChar->getName());
            foreach ($this->adminCharacterNames as $adminName) {
                if (strtolower($adminName) === $mainCharName) {
                    $hasAccess = true;
                    break;
                }
            }
        }

        return new JsonResponse([
            'hasAccess' => $hasAccess,
            'characterName' => $mainChar?->getName(),
        ]);
    }

    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        return new JsonResponse($this->adminService->getStats());
    }

    #[Route('/queues', name: 'api_admin_queues', methods: ['GET'])]
    public function getQueues(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        return new JsonResponse($this->adminService->getQueueStatus());
    }

    #[Route('/charts', name: 'api_admin_charts', methods: ['GET'])]
    public function getCharts(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        return new JsonResponse($this->adminService->getChartData());
    }

    #[Route('/actions/sync-assets', name: 'api_admin_action_sync_assets', methods: ['POST'])]
    public function triggerAssetsSync(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        $this->messageBus->dispatch(new TriggerAssetsSync());

        return new JsonResponse([
            'success' => true,
            'message' => 'Assets sync triggered',
        ]);
    }

    #[Route('/actions/sync-market', name: 'api_admin_action_sync_market', methods: ['POST'])]
    public function triggerMarketSync(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        $this->messageBus->dispatch(new TriggerJitaMarketSync());
        $this->messageBus->dispatch(new TriggerStructureMarketSync());

        return new JsonResponse([
            'success' => true,
            'message' => 'Market sync triggered (Jita + Structure)',
        ]);
    }

    #[Route('/actions/sync-pve', name: 'api_admin_action_sync_pve', methods: ['POST'])]
    public function triggerPveSync(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        $this->messageBus->dispatch(new TriggerPveSync());

        return new JsonResponse([
            'success' => true,
            'message' => 'PVE sync triggered',
        ]);
    }

    #[Route('/actions/sync-industry', name: 'api_admin_action_sync_industry', methods: ['POST'])]
    public function triggerIndustrySync(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        $this->messageBus->dispatch(new SyncIndustryJobs());

        return new JsonResponse([
            'success' => true,
            'message' => 'Industry jobs sync triggered',
        ]);
    }

    #[Route('/actions/sync-ansiblex', name: 'api_admin_action_sync_ansiblex', methods: ['POST'])]
    public function triggerAnsiblexSync(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        $this->messageBus->dispatch(new TriggerAnsiblexSync());

        return new JsonResponse([
            'success' => true,
            'message' => 'Ansiblex sync triggered',
        ]);
    }

    #[Route('/actions/retry-failed', name: 'api_admin_action_retry_failed', methods: ['POST'])]
    public function retryFailedMessages(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'messenger:failed:retry',
                '--force' => true,
                '--all' => true,
            ]);
            $output = new BufferedOutput();
            $application->run($input, $output);

            return new JsonResponse([
                'success' => true,
                'message' => 'Failed messages retry triggered',
                'output' => $output->fetch(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/actions/purge-failed', name: 'api_admin_action_purge_failed', methods: ['POST'])]
    public function purgeFailedMessages(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        try {
            $deleted = $this->connection->executeStatement(
                "DELETE FROM messenger_messages WHERE queue_name = 'failed'"
            );

            return new JsonResponse([
                'success' => true,
                'message' => "Purged {$deleted} failed messages",
                'deleted' => $deleted,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/actions/clear-cache', name: 'api_admin_action_clear_cache', methods: ['POST'])]
    public function clearCache(): JsonResponse
    {
        if ($error = $this->checkAdminAccess()) {
            return $error;
        }

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'cache:clear',
                '--no-warmup' => true,
            ]);
            $output = new BufferedOutput();
            $application->run($input, $output);

            return new JsonResponse([
                'success' => true,
                'message' => 'Cache cleared',
                'output' => $output->fetch(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
