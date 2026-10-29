<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\ApiResource\Input\GroupIndustry\UpdateSaleInput;
use App\Entity\Character;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\Service\GroupIndustry\ResolvesSaleOfProject;
use App\Service\GroupIndustry\ResolvesSellerOfUser;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateSaleInput, GroupIndustrySaleResource>
 */
final readonly class UpdateSaleProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;
    use ResolvesSellerOfUser;

    public function __construct(
        private Security $security,
        private GroupIndustryProjectRepository $projectRepository,
        private GroupIndustrySaleRepository $saleRepository,
        private GroupProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustrySaleResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof UpdateSaleInput);

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot correct a sale on a completed project');
        }

        $sale = $this->saleOf($this->saleRepository, $project, $uriVariables);
        $this->applyCorrection($sale, $data, $this->sellerOf($user, $data->sellerCharacterId));

        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'sale_updated',
            [
                'saleId' => $sale->getId()->toRfc4122(),
                'typeName' => $sale->getTypeName(),
                'quantity' => $sale->getQuantity(),
                'totalPrice' => $sale->getTotalPrice(),
            ],
        );

        return GroupIndustrySaleResource::fromSale($sale);
    }

    private function applyCorrection(GroupIndustrySale $sale, UpdateSaleInput $correction, ?Character $seller): void
    {
        if ($correction->typeId !== null) {
            $sale->setTypeId($correction->typeId);
        }
        if ($correction->typeName !== null) {
            $sale->setTypeName($correction->typeName);
        }
        if ($correction->quantity !== null) {
            $sale->setQuantity($correction->quantity);
        }
        if ($correction->unitPrice !== null) {
            $sale->setUnitPrice($correction->unitPrice);
        }
        if ($correction->venue !== null) {
            $sale->setVenue($correction->venue);
        }
        if ($correction->soldAt !== null) {
            $sale->setSoldAt(new \DateTimeImmutable($correction->soldAt));
        }
        if ($seller !== null) {
            $sale->setSeller($seller);
        }

        $sale->recalculateTotalPrice();
    }
}
