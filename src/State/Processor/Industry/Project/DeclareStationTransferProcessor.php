<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectStationTransferResource;
use App\ApiResource\Input\Industry\Project\DeclareStationTransferInput;
use App\Entity\User;
use App\Exception\InvalidStationTransferException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\Industry\Project\Transfer\StationTransferDeclarer;
use App\Service\Industry\Project\Transfer\StationTransferReading;
use App\Service\Industry\Project\Transfer\StationTransferRequest;
use App\Dto\Transfer\TransferEndpoint;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectStationTransferResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<DeclareStationTransferInput, ProjectStationTransferResource>
 */
final readonly class DeclareStationTransferProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private StationTransferDeclarer $declarer,
        private ProjectContainerReadings $containerReadings,
        private ProjectStationTransfers $transfers,
        private ProjectStationTransferResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStationTransferResource
    {
        \assert($data instanceof DeclareStationTransferInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        if ($project->isCompleted()) {
            throw new ConflictHttpException('The project is completed, its transfers can no longer change');
        }

        try {
            $legs = $this->declarer->declare($project, $user, $this->requestOf($data), new \DateTimeImmutable());
        } catch (InvalidStationTransferException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        $this->entityManager->flush();

        $firstLeg = $legs[0];
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'station_transfer_changed',
            ['routeId' => $firstLeg->getRouteId()->toRfc4122(), 'legIndex' => $firstLeg->getLegIndex(), 'action' => 'declared'],
        );

        $readings = array_values(array_filter(
            $this->transfers->of($project, $this->containerReadings->of($project)),
            static fn (StationTransferReading $reading): bool => $reading->leg === $firstLeg,
        ));

        return $this->mapper->toResources($project, $readings)[0] ?? throw new \LogicException('The declared leg is read back.');
    }

    private function requestOf(DeclareStationTransferInput $input): StationTransferRequest
    {
        $lines = $input->lines;
        if (!\is_array($lines)) {
            throw new \LogicException('The validated input carries the lines.');
        }

        return new StationTransferRequest(
            new TransferEndpoint(self::intOf($input->fromSolarSystemId), null === $input->fromLocationId ? null : self::intOf($input->fromLocationId)),
            new TransferEndpoint(self::intOf($input->toSolarSystemId), null === $input->toLocationId ? null : self::intOf($input->toLocationId)),
            array_values(array_map(
                static fn (array $line): array => ['typeId' => self::intOf($line['typeId']), 'quantity' => self::intOf($line['quantity'])],
                $lines,
            )),
            null === $input->carrierCharacterId ? null : self::intOf($input->carrierCharacterId),
            null === $input->contractId ? null : self::intOf($input->contractId),
        );
    }

    private static function intOf(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \LogicException('The validated input carries an integer.');
        }

        return $value;
    }
}
