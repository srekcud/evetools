<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

class MiningOperationMemberResource
{
    public ?string $id = null;

    public ?string $characterName = null;

    public ?int $corporationId = null;

    public ?string $role = null;

    public ?string $status = null;

    public ?\DateTimeImmutable $joinedAt = null;

    public array $characters = [];
}
