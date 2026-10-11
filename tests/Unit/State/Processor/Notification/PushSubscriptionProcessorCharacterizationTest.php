<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Notification;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\Provider\DeserializeProvider;
use ApiPlatform\State\SerializerContextBuilderInterface;
use App\ApiResource\Input\Notification\PushSubscriptionInput;
use App\ApiResource\Notification\PushSubscriptionResource;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\State\Processor\Notification\PushSubscriptionProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(PushSubscriptionProcessor::class)]
final class PushSubscriptionProcessorCharacterizationTest extends KernelTestCase
{
    private const string BROWSER_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/dQw4w9WgXcQ:APA91bHun4MxP5egoKMwt2KZFBaFUH';
    private const string BROWSER_P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const string BROWSER_AUTH = 'tBHItJI5svbpez7KI4CCXg';

    private HttpOperation $operation;
    private DeserializeProvider $deserializer;
    private User $user;
    private PushSubscriptionRepository&Stub $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private PushSubscriptionProcessor $processor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $operation = $container->get(ResourceMetadataCollectionFactoryInterface::class)
            ->create(PushSubscriptionResource::class)
            ->getOperation('_api_/me/push-subscription_post');
        self::assertInstanceOf(HttpOperation::class, $operation);
        self::assertSame('POST', $operation->getMethod());
        $this->operation = $operation->withDeserialize($operation->canDeserialize() ?? true);
        $this->deserializer = new DeserializeProvider(
            null,
            $container->get('api_platform.serializer'),
            $container->get(SerializerContextBuilderInterface::class),
        );

        $this->user = new User();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $this->repository = $this->createStub(PushSubscriptionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->processor = new PushSubscriptionProcessor($security, $this->repository, $this->entityManager);
    }

    #[Group('notif-audit-P1-1')]
    public function testBrowserPushSubscriptionJsonCurrentlyFailsOnUninitializedPublicKeyNotifAuditP11(): void
    {
        $this->repository->method('findByUserAndEndpoint')->willReturn(null);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $input = $this->deserialize(sprintf(
            '{"endpoint":"%s","expirationTime":null,"keys":{"p256dh":"%s","auth":"%s"}}',
            self::BROWSER_ENDPOINT,
            self::BROWSER_P256DH,
            self::BROWSER_AUTH,
        ));

        self::assertInstanceOf(PushSubscriptionInput::class, $input);
        self::assertSame(self::BROWSER_ENDPOINT, $input->endpoint);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Typed property App\ApiResource\Input\Notification\PushSubscriptionInput::$publicKey must not be accessed before initialization');

        $this->processor->process($input, $this->operation);
    }

    #[Group('notif-audit-P1-1')]
    public function testFlatSubscriptionJsonCurrentlyCreatesSubscriptionForCurrentUserNotifAuditP11(): void
    {
        $this->repository->method('findByUserAndEndpoint')->willReturn(null);
        $persisted = [];
        $this->entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->processor->process($this->deserialize(sprintf(
            '{"endpoint":"%s","publicKey":"%s","authToken":"%s"}',
            self::BROWSER_ENDPOINT,
            self::BROWSER_P256DH,
            self::BROWSER_AUTH,
        )), $this->operation);

        self::assertTrue($result->success);
        self::assertCount(1, $persisted);
        self::assertInstanceOf(PushSubscription::class, $persisted[0]);
        self::assertSame($this->user, $persisted[0]->getUser());
        self::assertSame(self::BROWSER_ENDPOINT, $persisted[0]->getEndpoint());
        self::assertSame(self::BROWSER_P256DH, $persisted[0]->getPublicKey());
        self::assertSame(self::BROWSER_AUTH, $persisted[0]->getAuthToken());
    }

    #[Group('notif-audit-P1-1')]
    public function testFlatSubscriptionJsonCurrentlyReplacesKeysOfKnownEndpointNotifAuditP11(): void
    {
        $existing = (new PushSubscription())
            ->setUser($this->user)
            ->setEndpoint(self::BROWSER_ENDPOINT)
            ->setPublicKey('BOldKeyFromPreviousRegistration')
            ->setAuthToken('oldAuthSecret');
        $this->repository->method('findByUserAndEndpoint')->willReturn($existing);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->processor->process($this->deserialize(sprintf(
            '{"endpoint":"%s","publicKey":"%s","authToken":"%s"}',
            self::BROWSER_ENDPOINT,
            self::BROWSER_P256DH,
            self::BROWSER_AUTH,
        )), $this->operation);

        self::assertSame(self::BROWSER_ENDPOINT, $existing->getEndpoint());
        self::assertSame(self::BROWSER_P256DH, $existing->getPublicKey());
        self::assertSame(self::BROWSER_AUTH, $existing->getAuthToken());
    }

    public function testRejectsNonHttpsEndpoint(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid push endpoint URL');

        $input = new PushSubscriptionInput();
        $input->endpoint = 'http://updates.push.services.mozilla.com/wpush/v2/gAAAAABk';

        $this->processor->process($input, $this->operation);
    }

    private function deserialize(string $json): mixed
    {
        $request = Request::create('/api/me/push-subscription', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $json);
        $request->attributes->set('input_format', 'json');

        return $this->deserializer->provide($this->operation, [], ['request' => $request]);
    }
}
