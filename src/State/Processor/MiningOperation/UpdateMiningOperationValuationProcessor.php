<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationValuationInput;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MarketSide;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningBoostFit;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\PersistedUuid;
use App\Service\TypeNameResolver;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateMiningOperationValuationInput, MiningOperationValuationResource>
 */
final readonly class UpdateMiningOperationValuationProcessor implements ProcessorInterface
{
    private const int MAX_CUT_PERCENT_SUM = 100;

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningOperationAccess $access,
        private MiningOperationValuator $valuator,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
        private MiningBoostFit $boostFit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationValuationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof UpdateMiningOperationValuationInput);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanAdminister($user, $miningOperation);

        if (!$miningOperation->getStatus()->isOpen()) {
            throw new BadRequestHttpException('The shares of this operation are frozen');
        }

        $form = isset($data->valuationForm) ? MiningValuationForm::from($data->valuationForm) : $miningOperation->getValuationForm();
        $market = isset($data->valuationMarket) ? MiningValuationMarket::from($data->valuationMarket) : $miningOperation->getValuationMarket();
        $side = isset($data->valuationSide) ? MarketSide::from($data->valuationSide) : $miningOperation->getValuationSide();
        $structureId = $this->isProvided($data, 'structureId') ? $data->structureId : $miningOperation->getValuationStructureId();
        $structureName = $this->isProvided($data, 'structureName') ? $data->structureName : $miningOperation->getValuationStructureName();
        $reprocessYield = $this->isProvided($data, 'reprocessYield') ? $data->reprocessYield : $miningOperation->getReprocessYield();
        $boosterCutPercent = $data->boosterCutPercent ?? $miningOperation->getBoosterCutPercent();
        $corporationCutPercent = $data->corporationCutPercent ?? $miningOperation->getCorporationCutPercent();
        $boosterMember = $this->isProvided($data, 'boosterMemberId') ? $this->memberChosenAs($data->boosterMemberId, $miningOperation) : $miningOperation->getBoosterMember();

        $boostShipProvided = $this->isProvided($data, 'boostShipTypeId');
        $boostShip = $boostShipProvided ? $this->boostShipChosenAs($data->boostShipTypeId) : $miningOperation->getBoostShip();
        $compressorKinds = match (true) {
            $this->isProvided($data, 'mountedCompressorKinds') => $this->compressorKindsChosenAs($data->mountedCompressorKinds),
            $boostShipProvided => [],
            default => $miningOperation->getMountedCompressorKinds(),
        };
        $this->assertBoostFitConsistent($boostShip, $compressorKinds);

        $this->assertConsistent($miningOperation, $form, $market, $structureId, $structureName, $reprocessYield, $boosterCutPercent, $corporationCutPercent, $boosterMember);

        $miningOperation->updateValuationSettings($form, $market, $side, $structureId, $structureName, $reprocessYield, $boosterCutPercent, $corporationCutPercent, $boosterMember, $boostShip, $compressorKinds);
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'valuation_updated',
        );

        $valuation = $this->valuator->value($miningOperation);

        return $this->mapper->valuationToResource(
            $miningOperation,
            $valuation,
            $this->typeNameResolver->findNames(array_keys($valuation->unitPricesByTypeId)),
            true,
        );
    }

    private function isProvided(UpdateMiningOperationValuationInput $input, string $field): bool
    {
        return (new \ReflectionProperty($input, $field))->isInitialized($input);
    }

    private function boostShipChosenAs(?int $boostShipTypeId): ?MiningBoostShip
    {
        return null === $boostShipTypeId ? null : MiningBoostShip::from($boostShipTypeId);
    }

    /**
     * @param list<string> $kindValues
     *
     * @return list<MiningCompressorKind>
     */
    private function compressorKindsChosenAs(array $kindValues): array
    {
        return array_map(MiningCompressorKind::from(...), $kindValues);
    }

    /**
     * @param list<MiningCompressorKind> $compressorKinds
     */
    private function assertBoostFitConsistent(?MiningBoostShip $boostShip, array $compressorKinds): void
    {
        if (null === $boostShip) {
            if ([] !== $compressorKinds) {
                $message = 'Compressors can only be mounted on a declared boost ship';

                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation($message, $message, [], $compressorKinds, 'mountedCompressorKinds', $compressorKinds),
                ]));
            }

            return;
        }

        $this->boostFit->assertMountable($boostShip, $compressorKinds);
    }

    private function memberChosenAs(?string $memberId, MiningOperation $miningOperation): ?MiningOperationMember
    {
        if ($memberId === null) {
            return null;
        }

        $member = Uuid::isValid($memberId) ? $this->memberRepository->find(Uuid::fromString($memberId)) : null;
        if ($member === null || $member->getOperation() !== $miningOperation) {
            throw new BadRequestHttpException('The booster must be an accepted participant');
        }

        return $member;
    }

    private function assertConsistent(
        MiningOperation $miningOperation,
        MiningValuationForm $form,
        MiningValuationMarket $market,
        ?int $structureId,
        ?string $structureName,
        ?float $reprocessYield,
        int $boosterCutPercent,
        int $corporationCutPercent,
        ?MiningOperationMember $boosterMember,
    ): void {
        if ($market === MiningValuationMarket::Structure && ($structureId === null || $structureName === null)) {
            throw new BadRequestHttpException('A structure market needs a structure');
        }
        if ($form === MiningValuationForm::Reprocessed && $reprocessYield === null) {
            throw new BadRequestHttpException('A reprocessed valuation needs a reprocessing yield');
        }
        if ($miningOperation->getSplitMode() === MiningSplitMode::EachForThemselves && ($boosterCutPercent > 0 || $corporationCutPercent > 0 || $boosterMember !== null)) {
            throw new BadRequestHttpException('An each-for-themselves operation has no booster or corporation cut');
        }
        if ($boosterCutPercent + $corporationCutPercent > self::MAX_CUT_PERCENT_SUM) {
            throw new BadRequestHttpException('Booster and corporation cuts cannot exceed 100 %');
        }
        if ($corporationCutPercent > 0 && $miningOperation->getCorporationId() === null) {
            throw new BadRequestHttpException('A corporation cut needs a known corporation');
        }
        if ($boosterMember !== null && $boosterMember->getStatus() !== MiningOperationMemberStatus::Accepted) {
            throw new BadRequestHttpException('The booster must be an accepted participant');
        }
    }
}
