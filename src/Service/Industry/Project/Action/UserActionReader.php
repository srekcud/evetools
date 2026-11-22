<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class UserActionReader
{
    /**
     * @param iterable<ProjectActionSource> $sources
     */
    public function __construct(
        private IndustryProjectParticipantRepository $memberRepository,
        #[AutowireIterator(ProjectActionSource::TAG)]
        private iterable $sources,
        private CharacterPositions $positions,
        private PlacedActionSorter $sorter,
    ) {
    }

    /**
     * @return list<LocatedAction>
     */
    public function ofUser(User $user): array
    {
        $projects = array_values(array_map(
            static fn (IndustryProjectParticipant $membership): IndustryProject => $membership->getProject(),
            $this->memberRepository->findAcceptedWithProjectsByUser($user),
        ));

        return $this->locate($projects, $user);
    }

    /**
     * @return list<LocatedAction>
     */
    public function ofProject(User $user, IndustryProject $project): array
    {
        return $this->locate([$project], $user);
    }

    /**
     * @param list<User> $users
     *
     * @return list<int> the number of actions of each user on the project, in the order of the users
     */
    public function countsOfProject(IndustryProject $project, array $users): array
    {
        $counts = [];
        foreach ($users as $user) {
            $count = 0;
            foreach ($this->sources as $source) {
                $count += \count($source->actionsOf($project, $user));
            }
            $counts[] = $count;
        }

        return $counts;
    }

    /**
     * @param list<IndustryProject> $projects
     *
     * @return list<LocatedAction>
     */
    private function locate(array $projects, User $user): array
    {
        $actions = [];
        foreach ($projects as $project) {
            foreach ($this->sources as $source) {
                foreach ($source->actionsOf($project, $user) as $action) {
                    $actions[] = $action;
                }
            }
        }

        return $this->sorter->sort($actions, $this->positions->of($user));
    }
}
