<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * 날짜/시각 변환 유틸.
 */
final class Dates
{
    /**
     * DB 값(문자열 또는 null)을 nullable DateTimeImmutable 로 변환한다.
     */
    public static function nullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
