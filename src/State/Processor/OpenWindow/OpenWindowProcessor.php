<?php

declare(strict_types=1);

namespace App\State\Processor\OpenWindow;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\OpenWindow\OpenWindowContractInput;
use App\ApiResource\Input\OpenWindow\OpenWindowInfoInput;
use App\ApiResource\Input\OpenWindow\OpenWindowMarketInput;
use App\ApiResource\OpenWindow\OpenWindowResource;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\OpenWindowService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<OpenWindowMarketInput|OpenWindowInfoInput|OpenWindowContractInput, OpenWindowResource>
 */
class OpenWindowProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly OpenWindowService $openWindowService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OpenWindowResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            throw new AccessDeniedHttpException('No EVE token available');
        }

        $result = new OpenWindowResource();

        try {
            match (true) {
                $data instanceof OpenWindowMarketInput => $this->openWindowService->openMarketDetails($token, $data->typeId),
                $data instanceof OpenWindowInfoInput => $this->openWindowService->openInformation($token, $data->targetId),
                $data instanceof OpenWindowContractInput => $this->openWindowService->openContract($token, $data->contractId),
                default => throw new \InvalidArgumentException('Unknown input type'),
            };

            $result->success = true;
        } catch (EsiApiException $e) {
            $this->logger->warning('Failed to open in-game window: {message}', [
                'message' => $e->getMessage(),
                'statusCode' => $e->statusCode,
                'endpoint' => $e->endpoint,
            ]);

            $result->success = false;
            $result->error = $e->getMessage();
        }

        return $result;
    }
}
