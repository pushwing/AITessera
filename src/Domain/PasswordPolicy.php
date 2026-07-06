<?php

declare(strict_types=1);

namespace App\Domain;

use Respect\Validation\Validatable;
use Respect\Validation\Validator as v;

/**
 * 비밀번호 정책 — 가입·비밀번호 변경 등에서 재사용하는 단일 규칙.
 *
 * 규칙: 10자 이상 + 영문자·숫자·특수문자를 각각 1개 이상 포함.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 10;

    public static function validator(): Validatable
    {
        return v::stringType()
            ->length(self::MIN_LENGTH, null)
            ->regex('/[A-Za-z]/')      // 영문자
            ->regex('/\d/')            // 숫자
            ->regex('/[^A-Za-z0-9]/'); // 특수문자
    }

    public static function describe(): string
    {
        return sprintf(
            '비밀번호는 %d자 이상이며 영문자·숫자·특수문자를 각각 1개 이상 포함해야 합니다.',
            self::MIN_LENGTH,
        );
    }
}
