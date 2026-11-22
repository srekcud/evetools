<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StructureAccess;

use App\Entity\Character;
use App\Enum\StructureAccessStatus;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;

final readonly class StructureAccessProbe
{
    public const string REQUIRED_SCOPE = 'esi-universe.read_structures.v1';

    private const int FORBIDDEN = 403;

    public function __construct(private EsiClient $esiClient)
    {
    }

    public function probe(Character $character, int $structureId): StructureAccessStatus
    {
        $token = $character->getEveToken();
        if (null === $token) {
            return StructureAccessStatus::Unknown;
        }

        $endpoint = sprintf('/universe/structures/%d/', $structureId);

        try {
            $this->esiClient->get($endpoint, $token);
        } catch (EsiApiException $answer) {
            return self::FORBIDDEN === $answer->statusCode && $endpoint === $answer->endpoint && $token->hasScope(self::REQUIRED_SCOPE)
                ? StructureAccessStatus::Denied
                : StructureAccessStatus::Unknown;
        } catch (EveAuthRequiredException|\RuntimeException) {
            return StructureAccessStatus::Unknown;
        }

        return StructureAccessStatus::Granted;
    }
}
