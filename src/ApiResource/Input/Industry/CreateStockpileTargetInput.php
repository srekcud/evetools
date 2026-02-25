<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Industry;

use Symfony\Component\Validator\Constraints as Assert;

class CreateStockpileTargetInput
{
    #[Assert\Positive]
    public int $typeId;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $typeName;

    #[Assert\Positive]
    public int $targetQuantity;

    #[Assert\Choice(choices: ['raw_material', 'intermediate', 'component', 'final_product'])]
    public string $stage;
}
