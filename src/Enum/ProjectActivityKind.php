<?php

declare(strict_types=1);

namespace App\Enum;

enum ProjectActivityKind: string
{
    case MemberJoined = 'member_joined';
    case MemberAccepted = 'member_accepted';
    case MemberLeft = 'member_left';
    case ContributionSubmitted = 'contribution_submitted';
    case ContributionApproved = 'contribution_approved';
    case ContributionRejected = 'contribution_rejected';
    case SaleRecorded = 'sale_recorded';
    case SaleUpdated = 'sale_updated';
    case SaleDeleted = 'sale_deleted';
    case StatusChanged = 'status_changed';
    case FeesUpdated = 'fees_updated';
    case JobsDetected = 'jobs_detected';
    case JobsFinished = 'jobs_finished';
}
