<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;

final class JobInstallerName
{
    public static function seenBy(?string $installerName, IndustryProject $project, User $viewer): ?string
    {
        if ($installerName === null) {
            return null;
        }

        $installer = ProjectJobInstaller::userOf($project, $installerName);

        return $installer === $viewer ? $installerName : PilotName::of($installer);
    }
}
