<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

/**
 * Empty DTO for operations that don't require input body.
 * Use this instead of `input: false` for POST operations without body.
 */
class EmptyInput
{
}
