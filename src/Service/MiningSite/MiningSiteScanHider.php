<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Entity\MiningSiteScan;
use App\Entity\User;
use App\Service\MiningOperation\MiningOperationAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class MiningSiteScanHider
{
    private const string NOT_AUTHOR_NOR_ADMINISTRATOR_MESSAGE = 'Only the scan author or an operation admin can remove this scan';

    public function __construct(
        private MiningOperationAccess $access,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function hide(User $actor, MiningSiteScan $scan): void
    {
        if (!$this->mayHide($actor, $scan)) {
            throw new AccessDeniedHttpException(self::NOT_AUTHOR_NOR_ADMINISTRATOR_MESSAGE);
        }

        $scan->hide(\DateTimeImmutable::createFromTimestamp($this->clock->now()->getTimestamp()));
        $this->entityManager->flush();
    }

    private function mayHide(User $actor, MiningSiteScan $scan): bool
    {
        if ($scan->getAuthor() === $actor) {
            return true;
        }

        $operation = $scan->getSite()->getOperation();

        return $operation !== null && $this->access->canAdminister($actor, $operation);
    }
}
