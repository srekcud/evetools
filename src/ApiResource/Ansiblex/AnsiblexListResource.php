<?php

declare(strict_types=1);

namespace App\ApiResource\Ansiblex;

class AnsiblexListResource
{
    public int $total;

    public ?int $allianceId = null;

    /** @var AnsiblexResource[] */
    public array $items = [];
}
