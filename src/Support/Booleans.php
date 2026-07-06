<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 불리언 파싱 유틸 — 요청 파라미터(쿼리스트링·JSON)의 다양한 불리언 표기를 통일한다.
 */
final class Booleans
{
    /**
     * 불리언 표기('1'/'0', 'true'/'false', true/false, 1/0)를 파싱한다. 인식 불가 시 null.
     */
    public static function parse(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            return match ((string) $value) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => null,
            };
        }

        return null;
    }
}
