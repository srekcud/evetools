<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Assets;

class UpdateCorpVisibilityInput
{
    /** @var int[] Division numbers (1-7) to make visible */
    public array $visibleDivisions = [];
}
