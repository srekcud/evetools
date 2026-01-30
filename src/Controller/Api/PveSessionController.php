<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PveSession;
use App\Repository\PveSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/pve/sessions')]
#[IsGranted('ROLE_USER')]
class PveSessionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PveSessionRepository $sessionRepository,
    ) {
    }

    #[Route('', name: 'pve_sessions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $days = (int) $request->query->get('days', 30);
        $limit = min((int) $request->query->get('limit', 50), 100);

        $to = new \DateTimeImmutable('now');
        $from = $to->modify("-{$days} days");

        $sessions = $this->sessionRepository->findByUserAndDateRange($user, $from, $to);
        $activeSession = $this->sessionRepository->findActiveSession($user);

        $sessionsData = array_map(fn(PveSession $s) => $this->serializeSession($s), $sessions);

        return new JsonResponse([
            'sessions' => array_slice($sessionsData, 0, $limit),
            'activeSession' => $activeSession ? $this->serializeSession($activeSession) : null,
            'summary' => [
                'totalSessions' => count($sessions),
                'totalTimeSeconds' => $this->sessionRepository->getTotalSessionTime($user, $from, $to),
                'averageIskPerHour' => $this->sessionRepository->getAverageIskPerHour($user, $from, $to),
            ],
        ]);
    }

    #[Route('/start', name: 'pve_sessions_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $user = $this->getUser();

        // Check if there's already an active session
        $activeSession = $this->sessionRepository->findActiveSession($user);
        if ($activeSession) {
            return new JsonResponse([
                'error' => 'A session is already active',
                'session' => $this->serializeSession($activeSession),
            ], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $session = new PveSession();
        $session->setUser($user);

        if (!empty($data['notes'])) {
            $session->setNotes(substr((string) $data['notes'], 0, 500));
        }

        $this->em->persist($session);
        $this->em->flush();

        return new JsonResponse([
            'message' => 'Session started',
            'session' => $this->serializeSession($session),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/stop', name: 'pve_sessions_stop', methods: ['POST'])]
    public function stop(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $session = $this->sessionRepository->find($id);

        if (!$session || $session->getUser() !== $user) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$session->isActive()) {
            return new JsonResponse([
                'error' => 'Session is not active',
                'session' => $this->serializeSession($session),
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $session->stop();

        if (!empty($data['notes'])) {
            $session->setNotes(substr((string) $data['notes'], 0, 500));
        }

        $this->em->flush();

        return new JsonResponse([
            'message' => 'Session stopped',
            'session' => $this->serializeSession($session),
        ]);
    }

    #[Route('/{id}', name: 'pve_sessions_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->getUser();
        $session = $this->sessionRepository->find($id);

        if (!$session || $session->getUser() !== $user) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'session' => $this->serializeSession($session, true),
        ]);
    }

    #[Route('/{id}', name: 'pve_sessions_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        $session = $this->sessionRepository->find($id);

        if (!$session || $session->getUser() !== $user) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($session);
        $this->em->flush();

        return new JsonResponse(['message' => 'Session deleted'], Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'pve_sessions_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $session = $this->sessionRepository->find($id);

        if (!$session || $session->getUser() !== $user) {
            return new JsonResponse(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('notes', $data)) {
            $notes = $data['notes'];
            $session->setNotes($notes ? substr((string) $notes, 0, 500) : null);
        }

        $this->em->flush();

        return new JsonResponse([
            'message' => 'Session updated',
            'session' => $this->serializeSession($session),
        ]);
    }

    #[Route('/active', name: 'pve_sessions_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $user = $this->getUser();
        $activeSession = $this->sessionRepository->findActiveSession($user);

        return new JsonResponse([
            'session' => $activeSession ? $this->serializeSession($activeSession) : null,
        ]);
    }

    private function serializeSession(PveSession $session, bool $includeDetails = false): array
    {
        $data = [
            'id' => (string) $session->getId(),
            'startedAt' => $session->getStartedAt()->format(\DateTimeInterface::ATOM),
            'endedAt' => $session->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'status' => $session->getStatus(),
            'notes' => $session->getNotes(),
            'durationSeconds' => $session->getDurationSeconds(),
            'durationFormatted' => $session->getDurationFormatted(),
            'totalIncome' => $session->getTotalIncome(),
            'totalExpenses' => $session->getTotalExpenses(),
            'profit' => $session->getProfit(),
            'iskPerHour' => $session->getIskPerHour(),
        ];

        if ($includeDetails) {
            $data['incomes'] = array_map(fn($income) => [
                'id' => (string) $income->getId(),
                'type' => $income->getType(),
                'description' => $income->getDescription(),
                'amount' => $income->getAmount(),
                'date' => $income->getDate()->format('Y-m-d'),
            ], $session->getIncomes()->toArray());

            $data['expenses'] = array_map(fn($expense) => [
                'id' => (string) $expense->getId(),
                'type' => $expense->getType(),
                'description' => $expense->getDescription(),
                'amount' => $expense->getAmount(),
                'date' => $expense->getDate()->format('Y-m-d'),
            ], $session->getExpenses()->toArray());
        }

        return $data;
    }
}
