<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Message\SyncAnsiblexGates;
use App\Repository\AnsiblexJumpGateRepository;
use App\Service\Sync\AnsiblexSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
class AnsiblexController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly AnsiblexJumpGateRepository $ansiblexRepository,
        private readonly AnsiblexSyncService $ansiblexSyncService,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/ansiblex', name: 'api_ansiblex_list', methods: ['GET'])]
    public function listAnsiblex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $allianceId = $mainCharacter->getAllianceId();

        // Filter by alliance if user is in one, otherwise show all active gates
        if ($allianceId) {
            $gates = $this->ansiblexRepository->findByAlliance($allianceId);
        } else {
            $gates = $this->ansiblexRepository->findAllActive();
        }

        $result = array_map(fn($gate) => [
            'structureId' => $gate->getStructureId(),
            'name' => $gate->getName(),
            'source' => [
                'solarSystemId' => $gate->getSourceSolarSystemId(),
                'solarSystemName' => $gate->getSourceSolarSystemName(),
            ],
            'destination' => [
                'solarSystemId' => $gate->getDestinationSolarSystemId(),
                'solarSystemName' => $gate->getDestinationSolarSystemName(),
            ],
            'owner' => [
                'allianceId' => $gate->getOwnerAllianceId(),
                'allianceName' => $gate->getOwnerAllianceName(),
            ],
            'isActive' => $gate->isActive(),
            'lastSeenAt' => $gate->getLastSeenAt()?->format('c'),
            'updatedAt' => $gate->getUpdatedAt()->format('c'),
        ], $gates);

        return new JsonResponse([
            'total' => count($result),
            'allianceId' => $allianceId,
            'items' => $result,
        ]);
    }

    #[Route('/ansiblex/refresh', name: 'api_ansiblex_refresh', methods: ['POST'])]
    public function refreshAnsiblex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->ansiblexSyncService->canSync($mainCharacter)) {
            return new JsonResponse([
                'error' => 'Cannot sync Ansiblex gates',
                'reason' => 'Missing required scope (esi-corporations.read_structures.v1) or invalid auth',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if sync should be async or sync based on request
        $async = $request->query->getBoolean('async', true);

        if ($async) {
            // Queue the sync
            $this->messageBus->dispatch(new SyncAnsiblexGates($mainCharacter->getId()->toRfc4122()));

            return new JsonResponse([
                'status' => 'queued',
                'message' => 'Ansiblex sync has been queued',
            ], Response::HTTP_ACCEPTED);
        }

        // Sync synchronously
        try {
            $stats = $this->ansiblexSyncService->syncFromCharacter($mainCharacter);

            $response = [
                'status' => 'completed',
                'stats' => $stats,
            ];

            // Add warning if no structures found
            if ($stats['added'] === 0 && $stats['updated'] === 0 && $stats['deactivated'] === 0) {
                $response['warning'] = 'No structures found. Character may lack Director/Station_Manager role in corporation.';
            }

            return new JsonResponse($response);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Sync failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/ansiblex/graph', name: 'api_ansiblex_graph', methods: ['GET'])]
    public function getAnsiblexGraph(): JsonResponse
    {
        $graph = $this->ansiblexRepository->getAdjacencyList();

        return new JsonResponse([
            'totalSystems' => count($graph),
            'graph' => $graph,
        ]);
    }

    /**
     * Discover Ansiblex gates using the search endpoint.
     * This works for any character with ACL access to gates, not just directors.
     */
    #[Route('/ansiblex/discover', name: 'api_ansiblex_discover', methods: ['POST'])]
    public function discoverAnsiblex(): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->ansiblexSyncService->canSyncViaSearch($mainCharacter)) {
            return new JsonResponse([
                'error' => 'Cannot discover Ansiblex gates',
                'reason' => 'Missing required scopes (esi-search.search_structures.v1 and esi-universe.read_structures.v1)',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $stats = $this->ansiblexSyncService->syncViaSearch($mainCharacter);

            return new JsonResponse([
                'status' => 'completed',
                'stats' => $stats,
                'message' => sprintf(
                    'Discovered %d structures, added %d new gates, updated %d existing gates',
                    $stats['discovered'],
                    $stats['added'],
                    $stats['updated']
                ),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Discovery failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
