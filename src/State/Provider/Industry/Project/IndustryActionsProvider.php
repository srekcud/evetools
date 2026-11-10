<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\IndustryActionResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;
use App\Service\Industry\Project\ActionAssessor;
use App\Service\Industry\Project\ActionQueueEntry;
use App\Service\Industry\Project\ActionQueueSorter;
use App\Service\Industry\Project\ActionSeverity;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectPriceAlert;
use App\Service\Industry\Project\ProjectPriceAlertReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<IndustryActionResource>
 */
class IndustryActionsProvider implements ProviderInterface
{
    private const array NO_ACTION_CODES = ['project_completed', 'nothing_to_do'];
    private const string PRICE_ALERT_CODE = 'price_below_break_even';
    private const string PRICE_ALERT_TAB = 'sales';

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectParticipantRepository $memberRepository,
        private readonly ProjectCockpitInputFactory $inputFactory,
        private readonly ProjectCockpitCalculator $calculator,
        private readonly ActionQueueSorter $sorter,
        private readonly ActionAssessor $assessor,
        private readonly ProjectPriceAlertReader $priceAlertReader,
    ) {
    }

    /** @return list<IndustryActionResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $entries = [];
        $priceAlerts = [];
        foreach ($this->memberRepository->findAcceptedWithProjectsByUser($user) as $membership) {
            $entry = $this->entryOf($membership, $user);
            if ($entry !== null) {
                $entries[] = $entry;
            }
            $priceAlert = $this->priceAlertReader->of($membership->getProject());
            if ($priceAlert !== null) {
                $priceAlerts[] = [$membership->getProject(), $priceAlert];
            }
        }

        return [
            ...array_map($this->priceAlertResource(...), $this->sortedByProject($priceAlerts)),
            ...array_map($this->toResource(...), $this->sorter->sort($entries)),
        ];
    }

    /**
     * @param list<array{IndustryProject, ProjectPriceAlert}> $priceAlerts
     *
     * @return list<array{IndustryProject, ProjectPriceAlert}>
     */
    private function sortedByProject(array $priceAlerts): array
    {
        usort($priceAlerts, static fn (array $left, array $right): int => strcmp($left[0]->getName() ?? '', $right[0]->getName() ?? '')
            ?: strcmp($left[0]->getId()->toRfc4122(), $right[0]->getId()->toRfc4122()));

        return $priceAlerts;
    }

    /**
     * @param array{IndustryProject, ProjectPriceAlert} $projectAlert
     */
    private function priceAlertResource(array $projectAlert): IndustryActionResource
    {
        [$project, $priceAlert] = $projectAlert;

        $resource = new IndustryActionResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->projectName = $project->getName();
        $resource->code = self::PRICE_ALERT_CODE;
        $resource->blocked = false;
        $resource->tab = self::PRICE_ALERT_TAB;
        $resource->typeId = $priceAlert->typeId;
        $resource->typeName = $priceAlert->typeName;
        $resource->quantity = $priceAlert->quantity;
        $resource->severity = ActionSeverity::Warning->value;
        $resource->jitaUnitPrice = $priceAlert->jitaUnitPrice;
        $resource->breakEvenPerUnit = $priceAlert->breakEvenPerUnit;

        return $resource;
    }

    private function entryOf(IndustryProjectParticipant $membership, User $user): ?ActionQueueEntry
    {
        $project = $membership->getProject();

        try {
            $nextAction = $this->calculator->calculate($this->inputFactory->create($project, $user))->nextAction;
        } catch (\UnexpectedValueException $failure) {
            throw new \UnexpectedValueException(
                sprintf('Next action of project %s cannot be computed: %s', $project->getId()->toRfc4122(), $failure->getMessage()),
                previous: $failure,
            );
        }

        if (\in_array($nextAction->code, self::NO_ACTION_CODES, true)) {
            return null;
        }

        return new ActionQueueEntry($project->getId()->toRfc4122(), $project->getName(), $nextAction);
    }

    private function toResource(ActionQueueEntry $entry): IndustryActionResource
    {
        $nextAction = $entry->nextAction;
        $assessment = $this->assessor->assess($nextAction);

        $resource = new IndustryActionResource();
        $resource->projectId = $entry->projectId;
        $resource->projectName = $entry->projectName;
        $resource->code = $nextAction->code;
        $resource->blocked = $nextAction->blocked;
        $resource->tab = $nextAction->tab;
        $resource->targetStatus = $nextAction->targetStatus;
        $resource->bomLineId = $nextAction->bomLineId;
        $resource->typeId = $nextAction->typeId;
        $resource->typeName = $nextAction->typeName;
        $resource->quantity = $nextAction->quantity;
        $resource->severity = $assessment->severity->value;
        $resource->impactIsk = $assessment->impactIsk;

        return $resource;
    }
}
