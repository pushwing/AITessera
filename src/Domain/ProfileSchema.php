<?php

declare(strict_types=1);

namespace App\Domain;

use Respect\Validation\Validatable;
use Respect\Validation\Validator as v;

/**
 * 소속(Affiliation)별 `profile`(JSON) 부가 항목 스키마.
 *
 * 소속마다 허용되는 필드와 타입을 정의하고, 가입·수정 시 profile 구조를 검증한다.
 * - 정의되지 않은 키 → 거절
 * - 타입 불일치 → 거절
 * - 각 필드는 선택(optional)이며, 제공된 경우에만 타입 검증한다.
 *
 * 필드 정의 근거: 이슈 #13. 상세는 docs/profile-fields.md 참고.
 */
final class ProfileSchema
{
    /**
     * 소속별 허용 필드 — 이름 => [검증기, 실패 메시지].
     *
     * @return array<string, array{0: Validatable, 1: string}>
     */
    public static function fields(Affiliation $affiliation): array
    {
        return match ($affiliation) {
            Affiliation::Aicura => [
                'age' => [v::intType()->positive(), 'age 는 양의 정수여야 합니다.'],
                'sex' => [v::stringType(), 'sex 는 문자열이어야 합니다.'],
                'where_from' => [v::stringType(), 'where_from 는 문자열이어야 합니다.'],
            ],
            Affiliation::Aicopia => [
                'gender' => [v::stringType(), 'gender 는 문자열이어야 합니다.'],
                'birthday' => [v::date('Y-m-d'), 'birthday 는 YYYY-MM-DD 형식이어야 합니다.'],
                'zipcode' => [v::stringType(), 'zipcode 는 문자열이어야 합니다.'],
                'address1' => [v::stringType(), 'address1 는 문자열이어야 합니다.'],
                'address2' => [v::stringType(), 'address2 는 문자열이어야 합니다.'],
            ],
            Affiliation::Aicreo, Affiliation::Aivance, Affiliation::Ailicet => [],
        };
    }

    /**
     * 소속별 허용 필드 이름 목록.
     *
     * @return list<string>
     */
    public static function allowedFields(Affiliation $affiliation): array
    {
        return array_keys(self::fields($affiliation));
    }

    /**
     * profile 을 소속 스키마로 검증하고 오류 메시지 목록을 반환한다(빈 배열이면 유효).
     *
     * @param array<array-key, mixed> $profile
     *
     * @return list<string>
     */
    public static function validate(Affiliation $affiliation, array $profile): array
    {
        $fields = self::fields($affiliation);
        $errors = [];

        foreach (array_keys($profile) as $key) {
            if (!is_string($key) || !array_key_exists($key, $fields)) {
                $errors[] = sprintf("'%s' 은(는) %s 소속에서 허용되지 않는 프로필 항목입니다.", (string) $key, $affiliation->value);
            }
        }

        foreach ($fields as $name => [$rule, $message]) {
            if (array_key_exists($name, $profile) && !$rule->validate($profile[$name])) {
                $errors[] = $message;
            }
        }

        return $errors;
    }
}
