<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Request\UpdateMeRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * 본인 정보 수정 DTO — 부분 수정·현재 비밀번호 필수 규칙 검증(이슈 #39).
 */
final class UpdateMeRequestTest extends TestCase
{
    public function testParsesProvidedFieldsOnly(): void
    {
        $dto = UpdateMeRequest::fromArray(['name' => '홍길동', 'contact' => '010-1234-5678']);

        self::assertSame('홍길동', $dto->name);
        self::assertSame('010-1234-5678', $dto->contact);
        self::assertTrue($dto->has('name'));
        self::assertFalse($dto->has('company'));
        self::assertNull($dto->password);
    }

    public function testCompanyCanBeExplicitlyCleared(): void
    {
        $dto = UpdateMeRequest::fromArray(['company' => '']);

        self::assertTrue($dto->has('company'));
        self::assertNull($dto->company);
    }

    public function testRoleAndActiveAreIgnoredAsNonFields(): void
    {
        // role·is_active 는 본인 수정 대상이 아니므로 "제공된 필드"로 취급되지 않는다.
        // 다른 유효 필드가 없으면 "수정할 필드가 없습니다"로 거절되어야 한다.
        $this->expectException(ValidationException::class);
        UpdateMeRequest::fromArray(['role' => 1, 'is_active' => false]);
    }

    public function testPasswordChangeRequiresCurrentPassword(): void
    {
        $this->expectException(ValidationException::class);
        UpdateMeRequest::fromArray(['password' => 'New!Passw0rd1']);
    }

    public function testPasswordChangeWithCurrentPasswordParses(): void
    {
        $dto = UpdateMeRequest::fromArray([
            'password' => 'New!Passw0rd1',
            'current_password' => 'Old!Passw0rd1',
        ]);

        self::assertSame('New!Passw0rd1', $dto->password);
        self::assertSame('Old!Passw0rd1', $dto->currentPassword);
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        UpdateMeRequest::fromArray([
            'password' => 'weak',
            'current_password' => 'Old!Passw0rd1',
        ]);
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        UpdateMeRequest::fromArray([]);
    }
}
