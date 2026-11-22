<?php

declare(strict_types=1);

namespace App\Exception;

final class InvalidLineBlueprintException extends \DomainException
{
    public const string LINE_NOT_BLUEPRINT_STEP = 'line_not_blueprint_step';
    public const string BLUEPRINT_NOT_VISIBLE = 'blueprint_not_visible';
    public const string BLUEPRINT_DOES_NOT_FIT_LINE = 'blueprint_does_not_fit_line';
}
