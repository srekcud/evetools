<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Me;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Me\UserResource;
use App\Entity\User;
use App\Security\JwtBlacklist;
use App\Service\Account\AccountDeletionService;
use App\State\Processor\Me\DeleteAccountProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(DeleteAccountProcessor::class)]
#[Group('issue-53')]
final class DeleteAccountProcessorTest extends TestCase
{
    private const string REQUEST_JWT = 'eyJhbGciOiJSUzI1NiJ9.account-of-kaelen.signature';
    private const string OTHER_JWT = 'eyJhbGciOiJSUzI1NiJ9.other-device-of-kaelen.signature';
    private const int JWT_TOKEN_TTL = 3_600;

    private JwtBlacklist $jwtBlacklist;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->jwtBlacklist = new JwtBlacklist(jwtBlacklist: new ArrayAdapter(), jwtTokenTtl: self::JWT_TOKEN_TTL);
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . self::REQUEST_JWT);
        $this->requestStack->push($request);
    }

    public function testDeletesTheAccountOfTheCurrentUserAndBlocksTheJwtOfTheRequest(): void
    {
        $currentUser = new User();
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::once())->method('delete')->with(self::identicalTo($currentUser));

        $result = $this->processor($currentUser, $accountDeletionService)->process(new UserResource(), new Delete());

        self::assertNull($result);
        self::assertTrue($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
        self::assertFalse($this->jwtBlacklist->isBlocked(self::OTHER_JWT));
    }

    public function testJwtOfTheRequestIsNotBlockedWhenTheDeletionFails(): void
    {
        $accountDeletionService = $this->createStub(AccountDeletionService::class);
        $accountDeletionService->method('delete')->willThrowException(new \RuntimeException('deletion failed'));
        $processor = $this->processor(new User(), $accountDeletionService);

        try {
            $processor->process(new UserResource(), new Delete());
            self::fail('The deletion failure must reach the caller');
        } catch (\RuntimeException $exception) {
            self::assertSame('deletion failed', $exception->getMessage());
        }

        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    public function testAnonymousRequestIsUnauthorizedAndDeletesNothing(): void
    {
        $accountDeletionService = $this->createMock(AccountDeletionService::class);
        $accountDeletionService->expects(self::never())->method('delete');
        $processor = $this->processor(null, $accountDeletionService);

        try {
            $processor->process(new UserResource(), new Delete());
            self::fail('An anonymous request must be unauthorized');
        } catch (UnauthorizedHttpException $exception) {
            self::assertSame(401, $exception->getStatusCode());
        }

        self::assertFalse($this->jwtBlacklist->isBlocked(self::REQUEST_JWT));
    }

    private function processor(?User $currentUser, AccountDeletionService $accountDeletionService): DeleteAccountProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        return new DeleteAccountProcessor(
            security: $security,
            accountDeletionService: $accountDeletionService,
            jwtBlacklist: $this->jwtBlacklist,
            requestStack: $this->requestStack,
        );
    }
}
