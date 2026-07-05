<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function passwords(): iterable
    {
        yield '유효 — 영문+숫자+특수, 10자 이상' => ['password1234!', true];
        yield '유효 — 대소문자 혼합' => ['Str0ng-Pass!', true];
        yield '무효 — 10자 미만' => ['Ab1!xyz', false];
        yield '무효 — 특수문자 없음' => ['password1234', false];
        yield '무효 — 숫자 없음' => ['password!!!!', false];
        yield '무효 — 영문자 없음' => ['1234567890!!', false];
    }

    #[DataProvider('passwords')]
    public function testPolicy(string $password, bool $expected): void
    {
        self::assertSame($expected, PasswordPolicy::validator()->validate($password));
    }
}
