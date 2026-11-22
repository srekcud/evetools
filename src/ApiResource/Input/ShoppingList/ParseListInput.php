<?php

declare(strict_types=1);

namespace App\ApiResource\Input\ShoppingList;

use App\Constant\EveConstants;
use Symfony\Component\Validator\Constraints as Assert;

class ParseListInput
{
    #[Assert\NotBlank]
    public string $text = '';

    public ?int $structureId = null;

    public float $transportCost = EveConstants::DEFAULT_EXPORT_COST_PER_M3;
}
