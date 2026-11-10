<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningOperation;
use App\Enum\MiningOperationMemberStatus;

final class SiteMinersCounter
{
    public function countPresent(MiningOperation $operation, \DateTimeImmutable $firstScanAt, \DateTimeImmutable $lastScanAt): int
    {
        $miners = 0;
        foreach ($operation->getMembers() as $member) {
            $departedAt = $member->getDepartedAt();
            if ($member->getStatus() !== MiningOperationMemberStatus::Accepted || ($departedAt !== null && $departedAt < $firstScanAt)) {
                continue;
            }
            foreach ($member->getCharacters() as $enrolment) {
                if ($enrolment->getEnrolledAt() <= $lastScanAt) {
                    ++$miners;
                }
            }
        }

        return $miners;
    }
}
