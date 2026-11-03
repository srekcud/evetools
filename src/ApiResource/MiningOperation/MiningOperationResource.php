<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

class MiningOperationResource
{
    public ?string $id = null;

    public ?string $label = null;

    public ?\DateTimeImmutable $plannedStartAt = null;

    public ?string $status = null;

    public ?string $visibility = null;

    public ?string $splitMode = null;

    public ?int $corporationId = null;

    public ?string $ownerCharacterName = null;

    public int $participantsCount = 0;

    public int $charactersCount = 0;

    public ?string $joinCode = null;

    public ?string $myRole = null;

    public ?string $myStatus = null;

    public ?\DateTimeImmutable $createdAt = null;
}
