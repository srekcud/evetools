<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectLineReservationResource;
use App\ApiResource\Input\Industry\Project\ReserveLineInput;
use App\Service\Industry\Project\LineReservationService;
use App\Service\Industry\Project\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Industry\Project\LineReservationScopeResolver;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<ReserveLineInput, ProjectLineReservationResource>
 */
final readonly class ReserveLineProcessor implements ProcessorInterface
{
    public function __construct(
        private LineReservationScopeResolver $scopeResolver,
        private LineReservationService $reservations,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectLineReservationResource
    {
        \assert($data instanceof ReserveLineInput);

        $scope = $this->scopeResolver->resolve($uriVariables);

        try {
            $reservation = $this->reservations->reserve($scope->participant, $scope->line, $data->quantity);
        } catch (\DomainException $exception) {
            throw new BadRequestHttpException($exception->getMessage());
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $scope->project->getId()->toRfc4122(),
            'line_reserved',
            [
                'bomItemId' => $scope->line->getId()->toRfc4122(),
                'bomItemName' => $scope->line->getTypeName(),
                'characterName' => PilotName::of($scope->participant->getUser()),
                'quantity' => $reservation->getQuantity(),
                'released' => false,
            ],
        );

        return ProjectLineReservationResource::fromEntity($reservation, PilotName::of($scope->participant->getUser()));
    }
}
