<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\EsiAuthFailureListener;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(EsiAuthFailureListener::class)]
class EsiAuthFailureListenerTest extends TestCase
{
    public function testEsiFailureBecomesHttpErrorWithEsiStatusCode(): void
    {
        $event = $this->createExceptionEvent(
            EsiApiException::fromResponse(503, 'ESI request failed', '/corporations/98000001/divisions/'),
        );

        (new EsiAuthFailureListener())->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame([
            'error' => 'ESI_API_ERROR',
            'message' => 'ESI request failed',
            'endpoint' => '/corporations/98000001/divisions/',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testEsiNetworkFailureWithoutHttpStatusBecomesBadGateway(): void
    {
        $event = $this->createExceptionEvent(
            EsiApiException::fromResponse(0, 'Network error: timeout', '/corporations/98000001/divisions/'),
        );

        try {
            (new EsiAuthFailureListener())->onKernelException($event);
        } catch (\InvalidArgumentException $crash) {
            $this->fail('An ESI network failure (status 0) must become an HTTP 502, the listener crashed instead: ' . $crash->getMessage());
        }

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame([
            'error' => 'ESI_API_ERROR',
            'message' => 'Network error: timeout',
            'endpoint' => '/corporations/98000001/divisions/',
        ], json_decode((string) $response->getContent(), true));
    }

    public function testEveAuthRequiredBecomesUnauthorized(): void
    {
        $event = $this->createExceptionEvent(new EveAuthRequiredException('2112000001'));

        (new EsiAuthFailureListener())->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'error' => 'EVE_AUTH_REQUIRED',
            'message' => 'EVE authentication required',
            'character_id' => '2112000001',
        ], json_decode((string) $response->getContent(), true));
    }

    private function createExceptionEvent(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/me/corporation/assets/visibility'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
