<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\UserRole;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    public function testBackedValuesMatchIssueSpec(): void
    {
        // 이슈 #29: 운영자=1, 대행사=2, 일반회원=3
        self::assertSame(1, UserRole::Operator->value);
        self::assertSame(2, UserRole::Agency->value);
        self::assertSame(3, UserRole::Member->value);
    }

    public function testFromIntResolvesCase(): void
    {
        self::assertSame(UserRole::Operator, UserRole::from(1));
        self::assertSame(UserRole::Member, UserRole::from(3));
        self::assertNull(UserRole::tryFrom(9));
    }

    public function testLabelIsKorean(): void
    {
        self::assertSame('운영자', UserRole::Operator->label());
        self::assertSame('대행사', UserRole::Agency->label());
        self::assertSame('일반회원', UserRole::Member->label());
    }
}
