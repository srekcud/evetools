<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\AdminAccessGuard;
use App\Service\Admin\FailedMessageHttpExceptionMapper;
use App\Service\Admin\FailedMessageService;

/**
 * @template TInput
 * @template TOutput of object|null
 *
 * @implements ProcessorInterface<TInput, TOutput>
 */
abstract class AbstractFailedMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AdminAccessGuard $accessGuard,
        protected readonly FailedMessageService $failedMessages,
        private readonly FailedMessageHttpExceptionMapper $exceptionMapper,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TOutput
     */
    final public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $this->accessGuard->requireAdmin();

        return $this->exceptionMapper->mapping(fn (): ?object => $this->apply($data, $uriVariables));
    }

    /**
     * @param array<string, mixed> $uriVariables
     *
     * @return TOutput
     */
    abstract protected function apply(mixed $data, array $uriVariables): ?object;
}
