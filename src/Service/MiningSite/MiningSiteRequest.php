<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Repository\MiningSiteRepository;
use App\Repository\MiningSiteScanRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\UuidOrNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MiningSiteRequest
{
    private const string SITE_NOT_FOUND_MESSAGE = 'Mining site not found';
    private const string SCAN_NOT_FOUND_MESSAGE = 'Mining site scan not found';

    public static function siteOf(MiningSiteRepository $repository, MiningOperationAccess $access, User $user, string $identifier): MiningSite
    {
        $site = $repository->find(UuidOrNotFound::of($identifier, self::SITE_NOT_FOUND_MESSAGE))
            ?? throw new NotFoundHttpException(self::SITE_NOT_FOUND_MESSAGE);
        $operation = $site->getOperation();
        if ($operation === null) {
            return $site->getOwner() === $user ? $site : throw new NotFoundHttpException(self::SITE_NOT_FOUND_MESSAGE);
        }

        if (!$access->canParticipate($user, $operation)) {
            throw new NotFoundHttpException(self::SITE_NOT_FOUND_MESSAGE);
        }

        return $site;
    }

    public static function scanOf(MiningSiteScanRepository $repository, MiningSite $site, string $identifier): MiningSiteScan
    {
        return $repository->findOfSite($site, UuidOrNotFound::of($identifier, self::SCAN_NOT_FOUND_MESSAGE))
            ?? throw new NotFoundHttpException(self::SCAN_NOT_FOUND_MESSAGE);
    }
}
