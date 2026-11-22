<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\IndustryProject;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(ProjectActionSource::TAG)]
interface ProjectActionSource
{
    public const string TAG = 'app.industry.project_action_source';

    /**
     * @return list<PlacedAction>
     */
    public function actionsOf(IndustryProject $project, User $user): array;
}
