<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 운영자용 회원 수정(PATCH) 요청 본문(이슈 #34).
 *
 * 부분 수정 — 제공한 필드만 변경한다. `email`·`affiliation` 은 수정 대상이 아니다.
 * 본인 계정의 `role`·`is_active` 는 변경할 수 없다(자기 잠금 방지).
 */
#[OA\Schema(
    schema: 'UpdateUserRequest',
    description: '회원 수정 정보. 제공한 필드만 반영된다.',
    properties: [
        new OA\Property(property: 'name', type: 'string', description: '이름', example: '홍길동'),
        new OA\Property(property: 'contact', type: 'string', description: '연락처', example: '010-1234-5678'),
        new OA\Property(property: 'company', type: 'string', nullable: true, description: '회사(빈 문자열·null 로 해제 가능)', example: 'AIvance'),
        new OA\Property(property: 'profile', type: 'object', nullable: true, description: '소속별 부가 항목(대상 회원 소속 스키마로 검증)', additionalProperties: true),
        new OA\Property(property: 'role', type: 'integer', enum: [1, 2, 3], description: '회원구분 1=운영자 2=대행사 3=일반회원', example: 2),
        new OA\Property(property: 'is_active', type: 'boolean', description: '활성 여부', example: true),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '새 비밀번호(10자 이상, 영문·숫자·특수문자 각 1개 이상)', example: 'newPass1234!'),
    ],
)]
final class UpdateUserRequest
{
}
