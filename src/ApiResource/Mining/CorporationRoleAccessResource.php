<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\Enum\CorporationRoleFeature;
use App\State\Processor\Mining\RecheckCorporationRoleAccessProcessor;
use App\State\Provider\Mining\CorporationRoleAccessProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[ApiResource(
    shortName: 'CorporationRoleAccess',
    description: 'Whether a character of the user holds the corporation role a feature needs, and who was checked',
    operations: [
        new Get(
            uriTemplate: '/mining/corporation-role-access/{corporationId}',
            uriVariables: [
                'corporationId' => new Link(fromClass: self::class),
            ],
            requirements: ['corporationId' => '\d+'],
            provider: CorporationRoleAccessProvider::class,
            openapi: new Model\Operation(summary: 'Read the corporation role access of the user for a feature', tags: ['Mining']),
        ),
        new Post(
            uriTemplate: '/mining/corporation-role-access/{corporationId}/recheck',
            uriVariables: [
                'corporationId' => new Link(fromClass: self::class),
            ],
            requirements: ['corporationId' => '\d+'],
            processor: RecheckCorporationRoleAccessProcessor::class,
            input: EmptyInput::class,
            status: 200,
            openapi: new Model\Operation(summary: 'Read the roles again, forgetting the remembered holder, and answer the fresh access', tags: ['Mining']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class CorporationRoleAccessResource
{
    private const string FEATURE_PARAMETER = 'feature';

    #[ApiProperty(identifier: true)]
    public int $corporationId;

    public string $status;

    public string $requiredRole;

    /** @var list<array{characterId: int, name: string, isMain: bool, state: string, roles: ?list<string>}> */
    public array $characters = [];

    public static function featureOf(?Request $request): CorporationRoleFeature
    {
        $feature = CorporationRoleFeature::tryFrom((string) $request?->query->get(self::FEATURE_PARAMETER));

        return $feature ?? throw new BadRequestHttpException(sprintf('The query parameter "%s" must be one of: %s', self::FEATURE_PARAMETER, implode(', ', array_column(CorporationRoleFeature::cases(), 'value'))));
    }

    /**
     * @param list<array{characterId: int, name: string, isMain: bool, state: string, roles: ?list<string>}> $characters
     */
    public static function of(int $corporationId, string $status, string $requiredRole, array $characters): self
    {
        $resource = new self();
        $resource->corporationId = $corporationId;
        $resource->status = $status;
        $resource->requiredRole = $requiredRole;
        $resource->characters = $characters;

        return $resource;
    }
}
