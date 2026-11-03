<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

class MiningOperationInvitationResource
{
    public ?string $code = null;

    public ?string $operationId = null;

    public ?string $label = null;

    public ?string $ownerCharacterName = null;

    public ?\DateTimeImmutable $plannedStartAt = null;

    public ?string $status = null;

    public ?string $visibility = null;

    public ?string $splitMode = null;

    public int $participantsCount = 0;

    public bool $autoAccepted = false;

    public ?string $myRole = null;

    public ?string $myStatus = null;

    public array $characters = [];
}
