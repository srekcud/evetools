<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\WebPushService;
use Base64Url\Base64Url;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(WebPushService::class)]
final class WebPushServiceCharacterizationTest extends TestCase
{
    private const string NOTIFICATION_ID = '0192a7c5-1b3d-7f20-a4e6-8c0b2d4f6a81';
    private const string VAPID_SUBJECT = 'mailto:fleet-ops@evetools.test';
    private const string SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @var resource|null */
    private static $pushServiceProcess = null;
    private static int $pushServicePort = 0;
    private static string $recordDirectory = '';

    private EntityManagerInterface&MockObject $entityManager;
    private BufferingLogger $logger;
    private User $user;

    /** @var list<object> */
    private array $removedEntities = [];

    /** @var array{publicKey: string, privateKey: string} */
    private array $vapidKeys;

    public static function setUpBeforeClass(): void
    {
        self::$recordDirectory = sys_get_temp_dir() . '/push-service-' . bin2hex(random_bytes(6));
        mkdir(self::$recordDirectory);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        self::$pushServicePort = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$pushServiceProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$pushServicePort, __DIR__ . '/../../../Fixtures/WebPush/push_service_router.php'],
            [0 => ['null'], 1 => ['null'], 2 => ['null']],
            $pipes,
            null,
            [...getenv(), 'PUSH_SERVICE_RECORD_DIR' => self::$recordDirectory],
        );
        register_shutdown_function(self::stopPushService(...));

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', self::$pushServicePort, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail('Fake push service did not start');
    }

    public static function tearDownAfterClass(): void
    {
        self::stopPushService();
    }

    private static function stopPushService(): void
    {
        if (self::$pushServiceProcess !== null) {
            proc_terminate(self::$pushServiceProcess);
            proc_close(self::$pushServiceProcess);
            self::$pushServiceProcess = null;
        }
        array_map(unlink(...), glob(self::$recordDirectory . '/*.json') ?: []);
        @rmdir(self::$recordDirectory);
    }

    protected function setUp(): void
    {
        array_map(unlink(...), glob(self::$recordDirectory . '/*.json') ?: []);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedEntities[] = $entity;
        });
        $this->logger = new BufferingLogger();
        $this->user = new User();
        $this->vapidKeys = VAPID::createVapidKeys();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function incompleteVapidConfigurations(): iterable
    {
        yield 'public key missing' => [null, 'private', self::VAPID_SUBJECT];
        yield 'private key missing' => ['public', '', self::VAPID_SUBJECT];
        yield 'subject missing' => ['public', 'private', null];
    }

    #[DataProvider('incompleteVapidConfigurations')]
    #[Group('notif-audit-P1-1')]
    public function testSkipsWithoutLookingUpDevicesWhenVapidConfigurationIsIncompleteCurrentlyLoggingDebugNotifAuditP11(?string $publicKey, ?string $privateKey, ?string $subject): void
    {
        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->expects($this->never())->method('findByUser');
        $this->entityManager->expects($this->never())->method('flush');

        $service = new WebPushService($repository, $this->entityManager, $this->logger, $publicKey, $privateKey, $subject);
        $service->send($this->user, $this->notification());

        self::assertSame([['debug', 'Web Push: VAPID keys not configured, skipping push notification', []]], $this->logger->cleanLogs());
    }

    public function testSendsNothingWhenUserHasNoDevice(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $this->service([])->send($this->user, $this->notification());

        self::assertSame([], $this->recordedRequests());
        self::assertSame([], $this->logger->cleanLogs());
    }

    #[Group('notif-audit-P2-8')]
    public function testPushPayloadCurrentlyCarriesNotificationIdWithoutTagNotifAuditP28(): void
    {
        $device = $this->device('201', 'payload-device');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service([$device['subscription']])->send($this->user, $this->notification());

        $requests = $this->recordedRequests();
        self::assertSame(['payload-device'], array_keys($requests));
        self::assertSame('POST', $requests['payload-device']['method']);
        self::assertSame('aesgcm', $requests['payload-device']['headers']['content-encoding']);
        self::assertSame([
            'title' => 'Extractor expired',
            'body' => 'Chiral Structures extractor on Dodixie IX has expired (Pilot Delta)',
            'category' => 'planetary',
            'level' => 'error',
            'route' => '/planetary',
            'data' => ['planetName' => 'Dodixie IX', 'colonyCount' => 3],
            'notificationId' => self::NOTIFICATION_ID,
        ], json_decode($this->decrypt($requests['payload-device'], $device), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([], $this->removedEntities);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testSignsEachRequestWithTheConfiguredVapidKeyAndSubject(): void
    {
        $device = $this->device('201', 'signed-device');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service([$device['subscription']])->send($this->user, $this->notification());

        $headers = $this->recordedRequests()['signed-device']['headers'];
        self::assertStringEndsWith(';p256ecdsa=' . $this->vapidKeys['publicKey'], $headers['crypto-key']);
        self::assertSame(1, preg_match('/^WebPush [^.]+\.([^.]+)\.[^.]+$/', $headers['authorization'], $jwt));
        $claims = json_decode(Base64Url::decode($jwt[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('http://127.0.0.1', $claims['aud']);
        self::assertSame(self::VAPID_SUBJECT, $claims['sub']);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testPushesANotificationThatHasNoIdentifierYet(): void
    {
        $device = $this->device('201', 'unsaved-notification-device');
        $this->entityManager->expects($this->once())->method('flush');
        $notification = (new Notification())
            ->setUser($this->user)
            ->setCategory(Notification::CATEGORY_INDUSTRY)
            ->setLevel(Notification::LEVEL_SUCCESS)
            ->setTitle('Job completed')
            ->setMessage('Hobgoblin I x23 manufacturing finished');

        $this->service([$device['subscription']])->send($this->user, $notification);

        $requests = $this->recordedRequests();
        self::assertSame(['unsaved-notification-device'], array_keys($requests));
        $payload = json_decode($this->decrypt($requests['unsaved-notification-device'], $device), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('notificationId', $payload);
        self::assertNull($payload['notificationId']);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testRemovesOnlyTheDevicesAnswered404Or410AndKeepsTheDelivered(): void
    {
        $delivered = $this->device('201', 'delivered-device');
        $gone = $this->device('410', 'gone-device');
        $notFound = $this->device('404', 'not-found-device');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service([$delivered['subscription'], $gone['subscription'], $notFound['subscription']])
            ->send($this->user, $this->notification());

        self::assertSame(['delivered-device', 'gone-device', 'not-found-device'], $this->sortedKeys($this->recordedRequests()));
        self::assertSame([$gone['subscription'], $notFound['subscription']], $this->removedEntities);
        self::assertSame([
            ['warning', 'Web Push delivery failed', ['endpoint' => $gone['endpoint'], 'reason' => $this->guzzleClientError($gone['endpoint'], '410 Gone')]],
            ['info', 'Removed expired push subscription', ['endpoint' => $gone['endpoint']]],
            ['warning', 'Web Push delivery failed', ['endpoint' => $notFound['endpoint'], 'reason' => $this->guzzleClientError($notFound['endpoint'], '404 Not Found')]],
            ['info', 'Removed expired push subscription', ['endpoint' => $notFound['endpoint']]],
        ], $this->logger->cleanLogs());
    }

    #[Group('notif-audit-P3-4')]
    public function testForbiddenDeviceIsCurrentlyKeptNotifAuditP34(): void
    {
        $forbidden = $this->device('403', 'forbidden-device');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service([$forbidden['subscription']])->send($this->user, $this->notification());

        self::assertSame(['forbidden-device'], array_keys($this->recordedRequests()));
        self::assertSame([], $this->removedEntities);
        self::assertSame([
            ['warning', 'Web Push delivery failed', ['endpoint' => $forbidden['endpoint'], 'reason' => $this->guzzleClientError($forbidden['endpoint'], '403 Forbidden')]],
        ], $this->logger->cleanLogs());
    }

    #[Group('notif-audit-P3-4')]
    public function testInvalidKeyOnOneDeviceCurrentlyCancelsDeliveryToEveryDeviceNotifAuditP34(): void
    {
        $healthy = $this->device('201', 'healthy-device');
        $corrupted = (new PushSubscription())
            ->setUser($this->user)
            ->setEndpoint($this->endpoint('201', 'corrupted-device'))
            ->setPublicKey(Base64Url::encode(str_repeat("\x05", 65)))
            ->setAuthToken(Base64Url::encode(str_repeat("\x07", 16)));
        $this->entityManager->expects($this->never())->method('flush');

        $this->service([$healthy['subscription'], $corrupted])->send($this->user, $this->notification());

        self::assertSame([], $this->recordedRequests());
        self::assertSame([], $this->removedEntities);
        self::assertSame([
            ['warning', 'Web Push sending failed', ['error' => 'Invalid data: only uncompressed keys are supported.']],
        ], $this->logger->cleanLogs());
    }

    /** @param list<PushSubscription> $subscriptions */
    private function service(array $subscriptions): WebPushService
    {
        $repository = $this->createStub(PushSubscriptionRepository::class);
        $repository->method('findByUser')->willReturn($subscriptions);

        return new WebPushService(
            $repository,
            $this->entityManager,
            $this->logger,
            $this->vapidKeys['publicKey'],
            $this->vapidKeys['privateKey'],
            self::VAPID_SUBJECT,
        );
    }

    private function notification(): Notification
    {
        $notification = (new Notification())
            ->setUser($this->user)
            ->setCategory(Notification::CATEGORY_PLANETARY)
            ->setLevel(Notification::LEVEL_ERROR)
            ->setTitle('Extractor expired')
            ->setMessage('Chiral Structures extractor on Dodixie IX has expired (Pilot Delta)')
            ->setData(['planetName' => 'Dodixie IX', 'colonyCount' => 3])
            ->setRoute('/planetary');
        (new \ReflectionProperty(Notification::class, 'id'))->setValue($notification, Uuid::fromString(self::NOTIFICATION_ID));

        return $notification;
    }

    /** @return array{subscription: PushSubscription, endpoint: string, privateKey: \OpenSSLAsymmetricKey, publicKey: string, authSecret: string} */
    private function device(string $status, string $name): array
    {
        $privateKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertNotFalse($details);
        $publicKey = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $authSecret = random_bytes(16);
        $endpoint = $this->endpoint($status, $name);

        $subscription = (new PushSubscription())
            ->setUser($this->user)
            ->setEndpoint($endpoint)
            ->setPublicKey(Base64Url::encode($publicKey))
            ->setAuthToken(Base64Url::encode($authSecret));

        return ['subscription' => $subscription, 'endpoint' => $endpoint, 'privateKey' => $privateKey, 'publicKey' => $publicKey, 'authSecret' => $authSecret];
    }

    private function endpoint(string $status, string $name): string
    {
        return sprintf('http://127.0.0.1:%d/%s/%s', self::$pushServicePort, $status, $name);
    }

    private function guzzleClientError(string $endpoint, string $statusLine): string
    {
        return sprintf('Client error: `POST %s` resulted in a `%s` response', $endpoint, $statusLine);
    }

    /** @return array<string, array{method: string, path: string, headers: array<string, string>, body: string}> */
    private function recordedRequests(): array
    {
        $requests = [];
        foreach (glob(self::$recordDirectory . '/*.json') ?: [] as $file) {
            $requests[basename($file, '.json')] = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    /**
     * @param array<string, mixed> $requests
     *
     * @return list<string>
     */
    private function sortedKeys(array $requests): array
    {
        $keys = array_keys($requests);
        sort($keys);

        return $keys;
    }

    /**
     * @param array{headers: array<string, string>, body: string} $request
     * @param array{privateKey: \OpenSSLAsymmetricKey, publicKey: string, authSecret: string} $device
     */
    private function decrypt(array $request, array $device): string
    {
        self::assertSame(1, preg_match('/^salt=([A-Za-z0-9_-]+)$/', $request['headers']['encryption'], $salt));
        self::assertSame(1, preg_match('/dh=([A-Za-z0-9_-]+)/', $request['headers']['crypto-key'], $serverKey));
        $serverPublicKey = Base64Url::decode($serverKey[1]);
        $serverPublicPem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin(self::SPKI_P256_PREFIX) . $serverPublicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $sharedSecret = openssl_pkey_derive($serverPublicPem, $device['privateKey']);
        self::assertNotFalse($sharedSecret);
        $sharedSecret = str_pad($sharedSecret, 32, "\0", STR_PAD_LEFT);

        $inputKeyingMaterial = $this->hkdf($device['authSecret'], $sharedSecret, "Content-Encoding: auth\0", 32);
        $context = "P-256\0\0A" . $device['publicKey'] . "\0A" . $serverPublicKey;
        $saltBytes = Base64Url::decode($salt[1]);
        $contentKey = $this->hkdf($saltBytes, $inputKeyingMaterial, "Content-Encoding: aesgcm\0" . $context, 16);
        $nonce = $this->hkdf($saltBytes, $inputKeyingMaterial, "Content-Encoding: nonce\0" . $context, 12);

        $body = base64_decode($request['body'], true);
        self::assertNotFalse($body);
        $padded = openssl_decrypt(substr($body, 0, -16), 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, substr($body, -16));
        self::assertNotFalse($padded);
        $paddingLength = unpack('n', substr($padded, 0, 2))[1];

        return substr($padded, 2 + $paddingLength);
    }

    private function hkdf(string $salt, string $inputKeyingMaterial, string $info, int $length): string
    {
        $pseudoRandomKey = hash_hmac('sha256', $inputKeyingMaterial, $salt, true);

        return substr(hash_hmac('sha256', $info . "\x01", $pseudoRandomKey, true), 0, $length);
    }
}
