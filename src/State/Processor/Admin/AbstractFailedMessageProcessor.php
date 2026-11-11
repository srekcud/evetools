<?php

declare(strict_types=1);

namespace App\State\Processor\Admin;

use ApiPlatform\State\ProcessorInterface;
use App\Security\AdminAccessGuard;
use App\Service\Admin\FailedMessageNotFound;
use App\Service\Admin\FailedMessageNotRedeliverable;
use App\Service\Admin\FailedMessagesUnavailable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @template TInput
 * @template TOutput
 *
 * @implements ProcessorInterface<TInput, TOutput>
 */
abstract class AbstractFailedMessageProcessor implements ProcessorInterface
{
    public function __construct(
        protected readonly AdminAccessGuard $accessGuard,
    ) {
    }

    /**
     * @template TResult
     *
     * @param callable(): TResult $action
     *
     * @return TResult
     */
    protected function mappingServiceFailures(callable $action): mixed
    {
        try {
            return $action();
        } catch (FailedMessageNotFound $notFound) {
            throw new NotFoundHttpException($notFound->getMessage(), $notFound);
        } catch (FailedMessageNotRedeliverable $notRedeliverable) {
            throw new UnprocessableEntityHttpException($notRedeliverable->getMessage(), $notRedeliverable);
        } catch (FailedMessagesUnavailable $unavailable) {
            throw new ServiceUnavailableHttpException(null, $unavailable->getMessage(), $unavailable);
        }
    }
}
