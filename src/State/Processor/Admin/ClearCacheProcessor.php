<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Admin\ActionResultResource;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @implements ProcessorInterface<mixed, ActionResultResource>
 */
class ClearCacheProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly KernelInterface $kernel,
        private readonly array $adminCharacterNames,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ActionResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $this->checkAdminAccess($user);

        $resource = new ActionResultResource();

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'cache:clear',
                '--no-warmup' => true,
            ]);
            $output = new BufferedOutput();
            $application->run($input, $output);

            $resource->success = true;
            $resource->message = 'Cache cleared';
            $resource->output = $output->fetch();
        } catch (\Throwable $e) {
            $resource->success = false;
            $resource->message = 'Error: ' . $e->getMessage();
        }

        return $resource;
    }

    private function checkAdminAccess(User $user): void
    {
        $mainChar = $user->getMainCharacter();
        if (!$mainChar) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $mainCharName = strtolower($mainChar->getName());
        $isAdmin = false;
        foreach ($this->adminCharacterNames as $adminName) {
            if (strtolower($adminName) === $mainCharName) {
                $isAdmin = true;
                break;
            }
        }

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Forbidden');
        }
    }
}
