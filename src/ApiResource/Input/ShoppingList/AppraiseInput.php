<?php

declare(strict_types=1);

namespace App\ApiResource\Input\ShoppingList;

use Symfony\Component\Validator\Constraints as Assert;

class AppraiseInput
{
    #[Assert\NotBlank]
    public string $text = '';
}
