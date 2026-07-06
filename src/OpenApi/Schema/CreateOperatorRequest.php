<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 운영자에 의한 계정 생성 요청 본문(이슈 #29).
 *
 * `role` 은 운영자(1)·대행사(2)만 허용한다. 약관 동의 필드는 받지 않으며, 서버가 생성 시각을 채운다.
 */
#[OA\Schema(
    schema: 'CreateOperatorRequest',
    description: '운영자·대행사 계정 생성 정보. 운영자 토큰으로만 호출한다.',
    required: ['email', 'password', 'role', 'affiliation', 'name', 'contact'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '로그인 ID 로 쓰이며 중복 불가', example: 'agency@aivance.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '10자 이상, 영문·숫자·특수문자 각 1개 이상', example: 'password1234!'),
        new OA\Property(property: 'role', type: 'integer', description: '회원구분 1=운영자 2=대행사 (일반회원 3 불가)', enum: [1, 2], example: 2),
        new OA\Property(property: 'affiliation', type: 'string', description: '소속 서비스 — 요청 운영자의 소속과 동일해야 한다', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
        new OA\Property(property: 'name', type: 'string', description: '이름', example: '대행사담당자'),
        new OA\Property(property: 'contact', type: 'string', description: '연락처', example: '010-1234-5678'),
        new OA\Property(property: 'company', type: 'string', nullable: true, description: '회사(선택)', example: 'AIvance'),
        new OA\Property(
            property: 'profile',
            type: 'object',
            nullable: true,
            description: '소속별 부가 항목(자가가입과 동일 스키마).',
            additionalProperties: true,
        ),
    ],
)]
final class CreateOperatorRequest
{
}
