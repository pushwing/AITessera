<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 본인 정보 수정(PATCH /api/v1/me) 요청 본문(이슈 #39).
 *
 * 부분 수정 — 제공한 필드만 변경한다. 회원구분(role)·활성상태(is_active)·이메일·소속은 대상이 아니다.
 * 비밀번호를 바꿀 때는 current_password 를 함께 보내 본인 확인을 거친다.
 */
#[OA\Schema(
    schema: 'UpdateMeRequest',
    description: '본인 수정 정보. 제공한 필드만 반영된다.',
    properties: [
        new OA\Property(property: 'name', type: 'string', description: '이름', example: '홍길동'),
        new OA\Property(property: 'contact', type: 'string', description: '연락처', example: '010-1234-5678'),
        new OA\Property(property: 'company', type: 'string', nullable: true, description: '회사(빈 문자열·null 로 해제 가능)', example: 'AIvance'),
        new OA\Property(property: 'profile', type: 'object', nullable: true, description: '소속별 부가 항목(본인 소속 스키마로 검증)', additionalProperties: true),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '새 비밀번호(10자 이상, 영문·숫자·특수문자 각 1개 이상)', example: 'newPass1234!'),
        new OA\Property(property: 'current_password', type: 'string', format: 'password', description: '현재 비밀번호 — password 변경 시 필수', example: 'oldPass1234!'),
    ],
)]
final class UpdateMeRequest
{
}
