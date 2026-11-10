<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningSite\NextMiningSiteInput;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Entity\MiningOperation;
use App\Entity\MiningSite;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningOperationRepository;
use App\Repository\MiningSiteRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\MiningSite\MiningSiteSolarSystemGuard;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProcessorInterface<NextMiningSiteInput, MiningSiteResource>
 */
final readonly class NextMiningSiteProcessor implements ProcessorInterface
{
    private const string SITE_NEXT_STARTED_EVENT = 'site_next_started';
    private const string NOT_OWNER_NOR_ADMINISTRATOR_MESSAGE = 'Only the owner of every active site or an operation admin can start the next site';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private MiningSiteSolarSystemGuard $solarSystemGuard,
        private EntityManagerInterface $entityManager,
        private MiningSiteResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof NextMiningSiteInput);

        $miningOperation = MiningOperationRequest::participatedOperationOf($this->operationRepository, $this->access, $user, $uriVariables['operationId']);
        MiningOperationRequest::assertNotArchived($miningOperation);
        $this->solarSystemGuard->assertKnown($data->solarSystemId);

        $activeSites = $this->siteRepository->findActiveOfOperation($miningOperation);
        $this->assertCanCloseAll($user, $miningOperation, $activeSites);

        $now = $this->clock->now();
        foreach ($activeSites as $activeSite) {
            $activeSite->setStatus(MiningSiteStatus::Closed)->setClosedAt($now);
        }

        $nextSite = (new MiningSite())
            ->setOwner($user)
            ->setOperation($miningOperation)
            ->setLabel(trim($data->label))
            ->setSolarSystemId($data->solarSystemId)
            ->setCreatedAt($now);
        $this->entityManager->persist($nextSite);
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            self::SITE_NEXT_STARTED_EVENT,
            [
                'siteId' => PersistedUuid::of($nextSite->getId()),
                'closedSiteIds' => array_map(static fn (MiningSite $site): string => PersistedUuid::of($site->getId()), $activeSites),
            ],
        );

        return $this->mapper->siteToResource($nextSite);
    }

    /**
     * @param list<MiningSite> $activeSites
     */
    private function assertCanCloseAll(User $user, MiningOperation $operation, array $activeSites): void
    {
        if ($this->access->canAdminister($user, $operation)) {
            return;
        }

        foreach ($activeSites as $activeSite) {
            if ($activeSite->getOwner() !== $user) {
                throw new AccessDeniedHttpException(self::NOT_OWNER_NOR_ADMINISTRATOR_MESSAGE);
            }
        }
    }
}
