<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AdminAccessGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminChecker $adminChecker,
    ) {
    }

    public function requireAdmin(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$this->adminChecker->isAdmin($user)) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        return $user;
    }
}
