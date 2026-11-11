<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class FailedMessageHttpExceptionMapper
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $action
     *
     * @return TResult
     */
    public function mapping(callable $action): mixed
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
