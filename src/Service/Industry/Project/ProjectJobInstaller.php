<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;

final class ProjectJobInstaller
{
    public static function userOf(IndustryProject $project, ?string $characterName): User
    {
        if ($characterName !== null) {
            foreach (self::participatingUsersOf($project) as $user) {
                foreach ($user->getCharacters() as $character) {
                    if ($character->getName() === $characterName) {
                        return $user;
                    }
                }
            }
        }

        return $project->getOwner();
    }

    /**
     * @return list<User>
     */
    private static function participatingUsersOf(IndustryProject $project): array
    {
        $users = [$project->getOwner()];
        foreach ($project->getMembers() as $member) {
            $user = $member->getUser();
            if ($user !== null && !\in_array($user, $users, true)) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
