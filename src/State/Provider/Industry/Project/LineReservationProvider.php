<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectLineReservationResource;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\LineReservationScopeResolver;
use App\Service\Industry\Project\PilotName;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<ProjectLineReservationResource>
 */
final readonly class LineReservationProvider implements ProviderInterface
{
    public function __construct(
        private LineReservationScopeResolver $scopeResolver,
        private IndustryProjectLineReservationRepository $reservationRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectLineReservationResource
    {
        $scope = $this->scopeResolver->resolve($uriVariables);

        $reservation = $this->reservationRepository->findOneByLineAndParticipant($scope->line, $scope->participant);
        if ($reservation === null) {
            throw new NotFoundHttpException('You have no reservation on this line');
        }

        return ProjectLineReservationResource::fromEntity($reservation, PilotName::of($scope->participant->getUser()));
    }
}
