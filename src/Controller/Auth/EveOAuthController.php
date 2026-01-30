<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\Service\ESI\AuthenticationService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Cache\CacheItemPoolInterface;

#[Route('/auth')]
class EveOAuthController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationService $authService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly CacheItemPoolInterface $jwtBlacklist,
        private readonly Security $security,
    ) {
    }

    #[Route('/eve/redirect', name: 'auth_eve_redirect', methods: ['GET'])]
    public function getRedirectUrl(): JsonResponse
    {
        $state = bin2hex(random_bytes(16));
        $url = $this->authService->getAuthorizationUrl($state);

        return new JsonResponse([
            'redirect_url' => $url,
            'state' => $state,
        ]);
    }

    #[Route('/eve/callback', name: 'auth_eve_callback', methods: ['GET'])]
    #[Route('/eve/exchange', name: 'auth_eve_exchange', methods: ['GET'])]
    public function callback(Request $request): JsonResponse
    {
        $code = $request->query->get('code');
        $error = $request->query->get('error');

        if ($error !== null) {
            return new JsonResponse([
                'error' => $error,
                'error_description' => $request->query->get('error_description'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($code === null) {
            return new JsonResponse([
                'error' => 'missing_code',
                'message' => 'Authorization code is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Check if user is already logged in (adding alt)
            $currentUser = $this->security->getUser();

            if ($currentUser instanceof User) {
                $character = $this->authService->addCharacterToUser($currentUser, $code);
                $token = $character->getEveToken();
                $scopes = $token ? $token->getScopes() : [];

                return new JsonResponse([
                    'message' => 'Character added successfully',
                    'character' => [
                        'id' => $character->getId()->toRfc4122(),
                        'name' => $character->getName(),
                        'scopes_count' => count($scopes),
                    ],
                ]);
            }

            // New login
            $user = $this->authService->authenticateWithCode($code);
            $jwt = $this->jwtManager->create($user);

            return new JsonResponse([
                'token' => $jwt,
                'user' => [
                    'id' => $user->getId()->toRfc4122(),
                    'main_character' => $user->getMainCharacter()?->getName(),
                ],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'authentication_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refreshToken(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => 'unauthorized',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $jwt = $this->jwtManager->create($user);

        return new JsonResponse([
            'token' => $jwt,
        ]);
    }

    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization');

        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $tokenId = hash('sha256', $token);

            // Add token to blacklist
            $cacheItem = $this->jwtBlacklist->getItem('jwt_blacklist_' . $tokenId);
            $cacheItem->set(true);
            $cacheItem->expiresAfter(3700); // 1 hour + buffer
            $this->jwtBlacklist->save($cacheItem);
        }

        return new JsonResponse([
            'message' => 'Logged out successfully',
        ]);
    }
}
