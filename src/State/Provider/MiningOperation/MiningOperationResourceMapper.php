<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\ApiResource\MiningOperation\MiningOperationMemberResource;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Service\MiningOperation\CharacterEligibility;

final readonly class MiningOperationResourceMapper
{
    public function operationToResource(MiningOperation $operation, ?MiningOperationMember $viewerMembership, bool $revealJoinCode = false): MiningOperationResource
    {
        return new MiningOperationResource();
    }

    public function memberToResource(MiningOperationMember $member): MiningOperationMemberResource
    {
        return new MiningOperationMemberResource();
    }

    public function invitationToResource(MiningOperation $operation, ?MiningOperationMember $viewerMembership, bool $autoAccepted, array $eligibilities): MiningOperationInvitationResource
    {
        return new MiningOperationInvitationResource();
    }
}
