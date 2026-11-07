<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

enum CourierContractState: string
{
    case NotShipped = 'not_shipped';
    case Waiting = 'waiting';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public static function fromEsiStatus(string $esiStatus): self
    {
        return match ($esiStatus) {
            'outstanding' => self::Waiting,
            'in_progress' => self::InTransit,
            'finished', 'finished_issuer', 'finished_contractor' => self::Delivered,
            'failed', 'rejected', 'cancelled', 'deleted', 'reversed' => self::Failed,
            default => self::Unknown,
        };
    }
}
