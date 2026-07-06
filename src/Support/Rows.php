<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DB row → 응답 뷰 매핑 공용 헬퍼.
 *
 * 여러 뷰(UserProfile·AdminUserView 등)가 공유하는 컬럼 변환 로직을 한곳에 둔다.
 */
final class Rows
{
    /**
     * 빈 문자열·비문자열은 null 로, 그 외 문자열은 그대로 돌려준다.
     */
    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * JSON 객체 컬럼(예: profile)을 연관 배열로 디코드한다. 비어 있거나 파싱 실패 시 빈 배열.
     *
     * @return array<array-key, mixed>
     */
    public static function decodeJsonObject(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
