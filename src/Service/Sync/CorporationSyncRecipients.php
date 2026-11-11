<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\Character;

final class CorporationSyncRecipients
{
    /**
     * @return list<string>
     */
    public static function of(Character $syncCharacter, ?string $requestedByUserId): array
    {
        $recipients = [];
        if ($requestedByUserId !== null) {
            $recipients[] = $requestedByUserId;
        }
        $ownerId = $syncCharacter->getUser()?->getId()?->toRfc4122();
        if ($ownerId !== null) {
            $recipients[] = $ownerId;
        }

        return array_values(array_unique($recipients));
    }
}
