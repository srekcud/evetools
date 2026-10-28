<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\RecordSaleInput;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\PilotName;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<RecordSaleInput, GroupIndustrySaleResource>
 */
class RecordSaleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustrySaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof RecordSaleInput);

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot record a sale on a completed project');
        }

        if ($data->quantity <= 0) {
            throw new BadRequestHttpException('Quantity must be positive');
        }

        if ($data->unitPrice < 0) {
            throw new BadRequestHttpException('Unit price must not be negative');
        }

        $soldAt = $data->soldAt !== null
            ? new \DateTimeImmutable($data->soldAt)
            : new \DateTimeImmutable();

        $sale = new GroupIndustrySale();
        $sale->setProject($project);
        $sale->setTypeId($data->typeId);
        $sale->setTypeName($data->typeName);
        $sale->setQuantity($data->quantity);
        $sale->setUnitPrice($data->unitPrice);
        $sale->setTotalPrice($data->quantity * $data->unitPrice);
        $sale->setVenue($data->venue);
        $sale->setSoldAt($soldAt);
        $sale->setRecordedBy($user);

        $this->entityManager->persist($sale);
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'sale_recorded',
            [
                'saleId' => $sale->getId()->toRfc4122(),
                'typeName' => $sale->getTypeName(),
                'quantity' => $sale->getQuantity(),
                'totalPrice' => $sale->getTotalPrice(),
            ],
        );

        return $this->mapToResource($sale);
    }

    private function mapToResource(GroupIndustrySale $sale): GroupIndustrySaleResource
    {
        $resource = new GroupIndustrySaleResource();
        $resource->id = $sale->getId()->toRfc4122();
        $resource->typeId = $sale->getTypeId();
        $resource->typeName = $sale->getTypeName();
        $resource->quantity = $sale->getQuantity();
        $resource->unitPrice = $sale->getUnitPrice();
        $resource->totalPrice = $sale->getTotalPrice();
        $resource->venue = $sale->getVenue();
        $resource->soldAt = $sale->getSoldAt()->format(\DateTimeInterface::ATOM);
        $resource->recordedByCharacterName = PilotName::of($sale->getRecordedBy());
        $resource->createdAt = $sale->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
