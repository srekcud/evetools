<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class EsiAuthFailureListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof EveAuthRequiredException) {
            $response = new JsonResponse([
                'error' => 'EVE_AUTH_REQUIRED',
                'message' => $exception->getMessage(),
                'character_id' => $exception->characterId,
            ], Response::HTTP_UNAUTHORIZED);

            $event->setResponse($response);
            return;
        }

        if ($exception instanceof EsiApiException) {
            $response = new JsonResponse([
                'error' => 'ESI_API_ERROR',
                'message' => $exception->getMessage(),
                'endpoint' => $exception->endpoint,
            ], $exception->statusCode);

            $event->setResponse($response);
        }
    }
}
