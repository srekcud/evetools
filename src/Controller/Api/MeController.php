<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\ESI\AuthenticationService;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly CharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
    ) {
    }

    #[Route('', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/characters', name: 'api_me_characters', methods: ['GET'])]
    public function characters(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->getSortedCharacters($user));
    }

    #[Route('/characters/{id}', name: 'api_me_character_delete', methods: ['DELETE'])]
    public function deleteCharacter(string $id): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $character = $this->characterRepository->find($id);

        if ($character === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Character not found'], Response::HTTP_NOT_FOUND);
        }

        if ($character->getUser() !== $user) {
            return new JsonResponse(['error' => 'forbidden', 'message' => 'Character does not belong to you'], Response::HTTP_FORBIDDEN);
        }

        if ($character->isMain()) {
            return new JsonResponse(['error' => 'bad_request', 'message' => 'Cannot delete main character'], Response::HTTP_BAD_REQUEST);
        }

        $user->removeCharacter($character);
        $this->entityManager->remove($character);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Character removed']);
    }

    #[Route('/characters/{id}/set-main', name: 'api_me_character_set_main', methods: ['POST'])]
    public function setMainCharacter(string $id): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $character = $this->characterRepository->find($id);

        if ($character === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Character not found'], Response::HTTP_NOT_FOUND);
        }

        if ($character->getUser() !== $user) {
            return new JsonResponse(['error' => 'forbidden', 'message' => 'Character does not belong to you'], Response::HTTP_FORBIDDEN);
        }

        $user->setMainCharacter($character);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Main character updated', 'character' => $this->serializeCharacter($character)]);
    }

    #[Route('/skillqueues', name: 'api_me_skillqueues', methods: ['GET'])]
    public function skillQueues(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $queues = [];

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $queue = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/skillqueue/",
                    $token
                );

                // Find the currently training skill (first with finish_date in the future)
                $now = new \DateTimeImmutable();
                $currentSkill = null;

                foreach ($queue as $item) {
                    if (!isset($item['finish_date'])) {
                        continue;
                    }
                    $finishDate = new \DateTimeImmutable($item['finish_date']);
                    if ($finishDate > $now) {
                        $currentSkill = $item;
                        break;
                    }
                }

                if ($currentSkill !== null) {
                    // Resolve skill name from SDE
                    $skillName = $this->resolveTypeName($currentSkill['skill_id']);

                    $queues[] = [
                        'characterId' => $character->getId()?->toRfc4122(),
                        'skillId' => $currentSkill['skill_id'],
                        'skillName' => $skillName,
                        'finishedLevel' => $currentSkill['finished_level'] ?? null,
                        'finishDate' => $currentSkill['finish_date'],
                        'queueSize' => count(array_filter($queue, fn($i) => isset($i['finish_date']) && new \DateTimeImmutable($i['finish_date']) > $now)),
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return new JsonResponse(['skillQueues' => $queues]);
    }

    private function resolveTypeName(int $typeId): string
    {
        $conn = $this->entityManager->getConnection();
        $name = $conn->fetchOne('SELECT type_name FROM sde_inv_types WHERE type_id = ?', [$typeId]);

        return $name !== false ? $name : "Skill #{$typeId}";
    }

    #[Route('/wallets', name: 'api_me_wallets', methods: ['GET'])]
    public function wallets(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $wallets = [];
        $totalBalance = 0.0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                // Refresh token if needed
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $balance = $this->esiClient->getScalar(
                    "/characters/{$character->getEveCharacterId()}/wallet/",
                    $token
                );

                $wallets[] = [
                    'characterId' => $character->getId()?->toRfc4122(),
                    'characterName' => $character->getName(),
                    'isMain' => $character->isMain(),
                    'balance' => $balance,
                ];
                $totalBalance += $balance;
            } catch (\Throwable $e) {
                // Skip characters with errors
                continue;
            }
        }

        // Sort: main first
        usort($wallets, fn($a, $b) => $b['isMain'] <=> $a['isMain']);

        return new JsonResponse([
            'wallets' => $wallets,
            'totalBalance' => $totalBalance,
        ]);
    }

    private function serializeUser(User $user): array
    {
        $mainCharacter = $user->getMainCharacter();

        return [
            'id' => $user->getId()?->toRfc4122(),
            'authStatus' => $user->getAuthStatus(),
            'mainCharacter' => $mainCharacter ? $this->serializeCharacter($mainCharacter) : null,
            'characters' => $this->getSortedCharacters($user),
            'corporationId' => $user->getCorporationId(),
            'corporationName' => $user->getCorporationName(),
            'createdAt' => $user->getCreatedAt()->format('c'),
            'lastLoginAt' => $user->getLastLoginAt()?->format('c'),
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function getSortedCharacters(User $user): array
    {
        $characters = array_map(
            fn(Character $c) => $this->serializeCharacter($c),
            $user->getCharacters()->toArray()
        );

        usort($characters, function ($a, $b) {
            if ($a['isMain'] !== $b['isMain']) {
                return $b['isMain'] <=> $a['isMain'];
            }
            return strcmp($a['name'], $b['name']);
        });

        return $characters;
    }

    private function serializeCharacter(Character $character): array
    {
        $token = $character->getEveToken();
        $hasValidToken = $token !== null && !empty($token->getScopes()) && $token->getScopes() !== [''];

        // Check if token is missing required scopes
        $hasMissingScopes = false;
        if ($hasValidToken && $token !== null) {
            $tokenScopes = $token->getScopes();
            $requiredScopes = AuthenticationService::getRequiredScopes();
            $missingScopes = array_diff($requiredScopes, $tokenScopes);
            $hasMissingScopes = !empty($missingScopes);
        }

        return [
            'id' => $character->getId()?->toRfc4122(),
            'eveCharacterId' => $character->getEveCharacterId(),
            'name' => $character->getName(),
            'corporationId' => $character->getCorporationId(),
            'corporationName' => $character->getCorporationName(),
            'allianceId' => $character->getAllianceId(),
            'allianceName' => $character->getAllianceName(),
            'isMain' => $character->isMain(),
            'hasValidToken' => $hasValidToken && !$hasMissingScopes,
            'hasMissingScopes' => $hasMissingScopes,
            'lastSyncAt' => $character->getLastSyncAt()?->format('c'),
        ];
    }
}
