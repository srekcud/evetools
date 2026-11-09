<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\ApiResource\Input\Industry\Project\RecordSaleFromContractInput;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\SaleChannel;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectSaleRepository;
use App\Service\Industry\Project\Contract\ContractSaleReader;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<RecordSaleFromContractInput, ProjectSaleResource>
 */
final readonly class RecordSaleFromContractProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;

    private const string ALREADY_LINKED_MESSAGE = 'This contract is already linked to a sale.';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectSaleRepository $saleRepository,
        private CachedWalletJournalEntryRepository $journalRepository,
        private ContractSaleReader $contractSaleReader,
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

        assert($data instanceof RecordSaleFromContractInput);
        assert($data->contractId !== null && $data->characterId !== null);

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot record a sale on a completed project');
        }

        if ($this->saleRepository->findOneBy(['contractId' => $data->contractId]) !== null) {
            throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE);
        }

        $candidate = $this->contractSaleReader->read($user, $project)->candidateOf($data->contractId, $data->characterId);
        $seller = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveCharacterId() === $data->characterId) {
                $seller = $character;
            }
        }
        if ($candidate === null || $seller === null) {
            throw new NotFoundHttpException('Contract not found among the contracts that sold an item of the project');
        }

        $sale = (new IndustryProjectSale())
            ->setProject($project)
            ->setTypeId($candidate->typeId)
            ->setTypeName($candidate->typeName)
            ->setQuantity($candidate->quantity)
            ->setUnitPrice($candidate->unitPrice())
            ->setTotalPrice($candidate->price)
            ->setSoldAt($candidate->completedAt)
            ->setRecordedBy($user)
            ->setSeller($seller)
            ->setChannel(SaleChannel::Contract)
            ->setContractId($candidate->contractId);

        $this->entityManager->persist($sale);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException(self::ALREADY_LINKED_MESSAGE, $exception);
        }

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

        return ProjectSaleResource::fromSale(
            $sale,
            $user,
            $this->journalRepository->contractFeesByContractId([$candidate->contractId])[$candidate->contractId] ?? null,
        );
    }
}
