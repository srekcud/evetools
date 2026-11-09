<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectLineReservationResource;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Industry\Project\LineReservationScopeResolver;
use App\Service\UuidOrNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ProjectLineReservationResource, void>
 */
final readonly class ReleaseLineProcessor implements ProcessorInterface
{
    public function __construct(
        private LineReservationScopeResolver $scopeResolver,
        private IndustryProjectLineReservationRepository $reservationRepository,
        private LineReservationService $reservations,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof ProjectLineReservationResource);

        $scope = $this->scopeResolver->resolve($uriVariables);

        $reservation = $this->reservationRepository->find(UuidOrNotFound::of($data->id, 'Reservation not found'));
        if ($reservation === null || $reservation->getParticipant() !== $scope->participant) {
            throw new NotFoundHttpException('You have no reservation on this line');
        }

        $this->reservations->release($reservation);

        $this->mercurePublisher->publishGroupProjectEvent(
            $scope->project->getId()->toRfc4122(),
            'line_reserved',
            [
                'bomItemId' => $scope->line->getId()->toRfc4122(),
                'bomItemName' => $scope->line->getTypeName(),
                'characterName' => PilotName::of($scope->participant->getUser()),
                'quantity' => 0,
                'released' => true,
            ],
        );
    }
}
