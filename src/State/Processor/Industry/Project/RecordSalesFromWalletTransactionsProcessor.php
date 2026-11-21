<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSaleResource;
use App\ApiResource\Industry\Project\RecordedSalesBatchResource;
use App\ApiResource\Input\Industry\Project\RecordSaleFromWalletTransactionInput;
use App\ApiResource\Input\Industry\Project\RecordSalesFromWalletTransactionsInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ResolvesSaleOfProject;
use App\Service\Mercure\MercurePublisherService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<RecordSalesFromWalletTransactionsInput, RecordedSalesBatchResource>
 */
final readonly class RecordSalesFromWalletTransactionsProcessor implements ProcessorInterface
{
    use ResolvesSaleOfProject;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private RecordSaleFromWalletTransactionProcessor $saleRecorder,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecordedSalesBatchResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof RecordSalesFromWalletTransactionsInput);

        $project = $this->projectOf($this->projectRepository, $uriVariables);
        $this->saleRecorder->assertMayRecord($user, $project);

        $batch = new RecordedSalesBatchResource();
        $activities = [];
        $quantity = 0;
        $totalPrice = 0.0;
        foreach ($data->transactions as $transaction) {
            assert($transaction instanceof RecordSaleFromWalletTransactionInput);

            try {
                $sale = $this->saleRecorder->record($user, $project, $transaction);
            } catch (HttpExceptionInterface $refusal) {
                $batch->failures[] = ['transactionId' => $transaction->transactionId, 'message' => $refusal->getMessage()];

                continue;
            }

            $batch->recorded[] = ProjectSaleResource::fromSale($sale, $user);
            $activities[] = $this->saleRecorder->activityOf($sale);
            $quantity += $sale->getQuantity();
            $totalPrice += $sale->getTotalPrice();
        }

        $this->mercurePublisher->publishGroupProjectEvents(
            $project->getId()->toRfc4122(),
            'sale_recorded',
            $activities,
            ['count' => count($activities), 'quantity' => $quantity, 'totalPrice' => $totalPrice],
        );

        return $batch;
    }
}
