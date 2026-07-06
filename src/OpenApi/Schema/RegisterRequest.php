<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 회원가입 요청 본문.
 */
#[OA\Schema(
    schema: 'RegisterRequest',
    description: '회원가입 정보. `profile` 은 소속별로 허용 필드가 다르다(docs/profile-fields.md).',
    required: ['email', 'password', 'affiliation', 'name', 'contact', 'terms_agreed', 'third_party_agreed'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '로그인 ID 로 쓰이며 중복 불가', example: 'user@aivance.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '10자 이상, 영문·숫자·특수문자 각 1개 이상', example: 'password1234!'),
        new OA\Property(property: 'affiliation', type: 'string', description: '소속 서비스', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
        new OA\Property(property: 'name', type: 'string', description: '이름(소속에 따라 닉네임/username 의미)', example: '홍길동'),
        new OA\Property(property: 'contact', type: 'string', description: '연락처', example: '010-1234-5678'),
        new OA\Property(property: 'company', type: 'string', nullable: true, description: '회사(선택)', example: 'AIvance'),
        new OA\Property(property: 'terms_agreed', type: 'boolean', description: '약관 동의(필수, true)', example: true),
        new OA\Property(property: 'third_party_agreed', type: 'boolean', description: '제3자 정보제공 동의(필수, true)', example: true),
        new OA\Property(
            property: 'profile',
            type: 'object',
            nullable: true,
            description: '소속별 부가 항목. 예) aicura: {age, sex, where_from}, aicopia: {gender, birthday, zipcode, address1, address2}. aicreo/aivance/ailicet 은 추가 필드 없음.',
            additionalProperties: true,
        ),
    ],
)]
final class RegisterRequest
{
}
