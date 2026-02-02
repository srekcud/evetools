<?php

declare(strict_types=1);

namespace App\State\Provider\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ShoppingList\StructureSearchResource;
use App\ApiResource\ShoppingList\StructureSearchResultResource;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<StructureSearchResource>
 */
class StructureSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StructureSearchResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new StructureSearchResource();
        $request = $this->requestStack->getCurrentRequest();
        $query = trim($request?->query->get('q', '') ?? '');

        if (strlen($query) < 3) {
            return $resource;
        }

        $token = null;
        $characterId = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                $characterId = $character->getEveCharacterId();
                break;
            }
        }

        if ($token === null || $characterId === null) {
            throw new BadRequestHttpException('No character with valid token');
        }

        try {
            $searchResult = $this->esiClient->get(
                "/characters/{$characterId}/search/?categories=structure&search=" . urlencode($query),
                $token
            );

            $structureIds = $searchResult['structure'] ?? [];
            $structureIds = array_slice($structureIds, 0, 10);

            $results = [];
            foreach ($structureIds as $structureId) {
                try {
                    $info = $this->esiClient->get("/universe/structures/{$structureId}/", $token);

                    $result = new StructureSearchResultResource();
                    $result->id = $structureId;
                    $result->name = $info['name'] ?? 'Unknown';
                    $result->typeId = $info['type_id'] ?? null;
                    $result->solarSystemId = $info['solar_system_id'] ?? null;

                    $results[] = $result;
                } catch (\Throwable $e) {
                    $this->logger->debug('Could not fetch structure info', [
                        'structureId' => $structureId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $resource->results = $results;
        } catch (\Throwable $e) {
            $this->logger->warning('Structure search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return $resource;
    }
}
