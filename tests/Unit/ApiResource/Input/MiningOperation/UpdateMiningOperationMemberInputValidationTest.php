<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\MiningOperation;

use App\ApiResource\Input\MiningOperation\UpdateMiningOperationMemberInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateMiningOperationMemberInput::class)]
#[Group('issue-262')]
final class UpdateMiningOperationMemberInputValidationTest extends TestCase
{
    use ViolatedFieldsOf;

    public function testPromotionToAdminIsValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->update(role: 'admin')));
    }

    public function testAcceptanceIsValid(): void
    {
        self::assertSame([], $this->violatedFieldsOf($this->update(status: 'accepted')));
    }

    public function testPromotionToOwnerIsRejected(): void
    {
        self::assertSame(['role'], $this->violatedFieldsOf($this->update(role: 'owner')));
    }

    public function testRemovalThroughTheStatusIsRejected(): void
    {
        self::assertSame(['status'], $this->violatedFieldsOf($this->update(status: 'removed')));
    }

    private function update(?string $role = null, ?string $status = null): UpdateMiningOperationMemberInput
    {
        $input = new UpdateMiningOperationMemberInput();
        $input->role = $role;
        $input->status = $status;

        return $input;
    }
}
