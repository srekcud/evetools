<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\CharacterInfoDto;
use App\Dto\EveTokenDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CharacterRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuthenticationService
{
    private const EVE_AUTHORIZE_URL = 'https://login.eveonline.com/v2/oauth/authorize';
    private const EVE_TOKEN_URL = 'https://login.eveonline.com/v2/oauth/token';
    private const EVE_VERIFY_URL = 'https://login.eveonline.com/oauth/verify';
    private const REQUEST_TIMEOUT = 15;

    private const REQUIRED_SCOPES = [
        // Assets & Corporation
        'esi-assets.read_assets.v1',
        'esi-assets.read_corporation_assets.v1',
        'esi-characters.read_corporation_roles.v1',
        'esi-corporations.read_divisions.v1',
        'esi-corporations.read_structures.v1',

        // Wallet & Contracts (PVE)
        'esi-wallet.read_character_wallet.v1',
        'esi-contracts.read_character_contracts.v1',

        // Industry & Mining
        'esi-industry.read_character_jobs.v1',
        'esi-industry.read_corporation_jobs.v1',
        'esi-industry.read_character_mining.v1',
        'esi-industry.read_corporation_mining.v1',
        'esi-characters.read_blueprints.v1',
        'esi-corporations.read_blueprints.v1',

        // Skills
        'esi-skills.read_skills.v1',
        'esi-skills.read_skillqueue.v1',

        // Location & Fleet
        'esi-location.read_location.v1',
        'esi-location.read_ship_type.v1',
        'esi-location.read_online.v1',
        'esi-fleets.read_fleet.v1',

        // Universe & Search
        'esi-universe.read_structures.v1',
        'esi-search.search_structures.v1',

        // Intel
        'esi-characters.read_notifications.v1',
        'esi-killmails.read_killmails.v1',

        // Market (prix citadelles)
        'esi-markets.structure_markets.v1',

        // UI (quality of life)
        'esi-ui.open_window.v1',

        // Corporation Projects (opportunités)
        'esi-corporations.read_projects.v1',

        // Planetary Interaction
        'esi-planets.manage_planets.v1',
    ];

    /**
     * @return string[]
     */
    public static function getRequiredScopes(): array
    {
        return self::REQUIRED_SCOPES;
    }

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TokenManager $tokenManager,
        private readonly CharacterService $characterService,
        private readonly UserRepository $userRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $callbackUrl,
    ) {
    }

    public function getAuthorizationUrl(?string $state = null): string
    {
        $params = [
            'response_type' => 'code',
            'redirect_uri' => $this->callbackUrl,
            'client_id' => $this->clientId,
            'scope' => implode(' ', self::REQUIRED_SCOPES),
        ];

        if ($state !== null) {
            $params['state'] = $state;
        }

        return self::EVE_AUTHORIZE_URL . '?' . http_build_query($params);
    }

    public function exchangeCodeForToken(string $code): EveTokenDto
    {
        try {
            $response = $this->httpClient->request('POST', self::EVE_TOKEN_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = $response->toArray();

            // Parse scopes from response, filter empty values
            $scopes = array_filter(explode(' ', $data['scope'] ?? ''), fn($s) => $s !== '');

            // If scopes are empty, try to extract from JWT access token
            if (empty($scopes)) {
                $scopes = $this->tokenManager->extractScopesFromJwt($data['access_token']);
            }

            return new EveTokenDto(
                accessToken: $data['access_token'],
                refreshToken: $data['refresh_token'],
                expiresIn: $data['expires_in'],
                scopes: $scopes,
            );
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::unauthorized('Network error during authentication: ' . $e->getMessage());
        }
    }

    public function verifyToken(string $accessToken): int
    {
        try {
            $response = $this->httpClient->request('GET', self::EVE_VERIFY_URL, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = $response->toArray();

            return (int) $data['CharacterID'];
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::unauthorized('Network error during token verification: ' . $e->getMessage());
        }
    }

    public function authenticateWithCode(string $code): User
    {
        // Exchange code for tokens
        $tokenDto = $this->exchangeCodeForToken($code);

        // Verify token and get character ID
        $characterId = $this->verifyToken($tokenDto->accessToken);

        // Get character info from ESI
        $characterInfo = $this->characterService->getCharacterInfo($characterId);

        // Find or create character
        $character = $this->findOrCreateCharacter($characterInfo, $tokenDto);

        // Get or create user
        $user = $character->getUser();

        // Update last login
        $user->updateLastLogin();
        $user->markAuthValid();

        $this->entityManager->flush();

        return $user;
    }

    public function addCharacterToUser(User $user, string $code): Character
    {
        // Exchange code for tokens
        $tokenDto = $this->exchangeCodeForToken($code);

        // Verify token and get character ID
        $characterId = $this->verifyToken($tokenDto->accessToken);

        // Check if character already exists
        $existingCharacter = $this->characterRepository->findByEveCharacterId($characterId);

        if ($existingCharacter !== null) {
            if ($existingCharacter->getUser() === $user) {
                // Update tokens for existing character
                $this->updateCharacterToken($existingCharacter, $tokenDto);
                $this->entityManager->flush();

                return $existingCharacter;
            }

            throw new \RuntimeException('This character is already linked to another account');
        }

        // Get character info from ESI
        $characterInfo = $this->characterService->getCharacterInfo($characterId);

        // Create new character
        $character = $this->createCharacter($characterInfo, $tokenDto);
        $character->setUser($user);
        $user->addCharacter($character);

        $this->entityManager->flush();

        return $character;
    }

    private function findOrCreateCharacter(CharacterInfoDto $info, EveTokenDto $tokenDto): Character
    {
        $character = $this->characterRepository->findByEveCharacterId($info->characterId);

        if ($character !== null) {
            // Update character info and tokens
            $this->updateCharacterInfo($character, $info);
            $this->updateCharacterToken($character, $tokenDto);

            return $character;
        }

        // Create new character and user
        $character = $this->createCharacter($info, $tokenDto);

        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $character;
    }

    private function createCharacter(CharacterInfoDto $info, EveTokenDto $tokenDto): Character
    {
        $character = new Character();
        $character->setEveCharacterId($info->characterId);
        $character->setName($info->characterName);
        $character->setCorporationId($info->corporationId);
        $character->setCorporationName($info->corporationName);
        $character->setAllianceId($info->allianceId);
        $character->setAllianceName($info->allianceName);

        $token = $this->tokenManager->createTokenFromDto($tokenDto);
        $token->setCharacter($character);
        $character->setEveToken($token);

        $this->entityManager->persist($character);

        return $character;
    }

    private function updateCharacterInfo(Character $character, CharacterInfoDto $info): void
    {
        $character->setName($info->characterName);
        $character->setCorporationId($info->corporationId);
        $character->setCorporationName($info->corporationName);
        $character->setAllianceId($info->allianceId);
        $character->setAllianceName($info->allianceName);
    }

    private function updateCharacterToken(Character $character, EveTokenDto $tokenDto): void
    {
        $existingToken = $character->getEveToken();

        if ($existingToken !== null) {
            $existingToken->setAccessToken($tokenDto->accessToken);
            $existingToken->setRefreshTokenEncrypted(
                $this->tokenManager->encryptRefreshToken($tokenDto->refreshToken)
            );
            $existingToken->setAccessTokenExpiresAt($tokenDto->getExpiresAt());
            $existingToken->setScopes($tokenDto->scopes);
        } else {
            $token = $this->tokenManager->createTokenFromDto($tokenDto);
            $token->setCharacter($character);
            $character->setEveToken($token);
            $this->entityManager->persist($token);
        }
    }
}
