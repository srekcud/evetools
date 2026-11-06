<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\ApiResource\Input\Industry\Project\UpdateSaleInput;
use App\Entity\Character;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Repository\IndustryProjectSaleRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use App\Service\Industry\Project\ResolvesSellerOfUser;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateSaleInput, ProjectSaleResource>
 */
final readonly class UpdateSaleProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;
    use ResolvesSellerOfUser;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectSaleRepository $saleRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectSaleResource
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
        $this->assertCorrectionKeepsTheWalletTransaction($sale, $data);
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

        return ProjectSaleResource::fromSale($sale);
    }

    private function assertCorrectionKeepsTheWalletTransaction(IndustryProjectSale $sale, UpdateSaleInput $correction): void
    {
        $transaction = $sale->getWalletTransaction();
        if ($transaction === null) {
            return;
        }

        if ($correction->typeId !== null
            || $correction->unitPrice !== null
            || $correction->soldAt !== null
            || $correction->sellerCharacterId !== null) {
            throw new BadRequestHttpException('This sale is linked to a wallet transaction.');
        }

        if ($correction->quantity !== null && $correction->quantity > $transaction->getQuantity()) {
            throw new BadRequestHttpException('The quantity exceeds the quantity of the transaction.');
        }
    }

    private function applyCorrection(IndustryProjectSale $sale, UpdateSaleInput $correction, ?Character $seller): void
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
