<?php

declare(strict_types=1);

namespace App\Enum;

enum CorporationProjectReadFailure: string
{
    case NoValidCharacter = 'no_valid_character';
    case MissingProjectsScope = 'missing_projects_scope';
    case EsiError = 'esi_error';
    case UnexpectedResponse = 'unexpected_response';
}
