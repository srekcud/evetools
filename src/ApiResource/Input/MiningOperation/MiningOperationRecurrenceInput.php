<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use Symfony\Component\Validator\Constraints as Assert;

class MiningOperationRecurrenceInput
{
    #[Assert\Choice(choices: ['weekly', 'chunk'])]
    public string $kind = '';

    public ?int $structureId = null;
}
