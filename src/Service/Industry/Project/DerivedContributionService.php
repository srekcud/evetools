<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;

class DerivedContributionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function refreshLine(IndustryProjectBomLine $line): void
    {
    }
}
