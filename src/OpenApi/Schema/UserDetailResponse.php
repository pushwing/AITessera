<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 운영자용 회원 상세 응답(이슈 #34).
 */
#[OA\Schema(
    schema: 'UserDetailResponse',
    description: '회원 상세 정보(회원구분·활성상태 포함). 민감정보 제외.',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 10),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'member@aivance.test'),
                new OA\Property(property: 'name', type: 'string', example: '홍길동'),
                new OA\Property(property: 'affiliation', type: 'string', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
                new OA\Property(property: 'role', type: 'integer', enum: [1, 2, 3], description: '회원구분 1=운영자 2=대행사 3=일반회원', example: 3),
                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                new OA\Property(property: 'contact', type: 'string', nullable: true, example: '010-1234-5678'),
                new OA\Property(property: 'company', type: 'string', nullable: true, example: 'AIvance'),
                new OA\Property(property: 'profile', type: 'object', nullable: true, description: '소속별 부가 항목', additionalProperties: true),
                new OA\Property(property: 'email_verified', type: 'boolean', example: true),
                new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true, example: '2026-07-06 09:00:00'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-01 09:00:00'),
            ],
        ),
    ],
)]
final class UserDetailResponse
{
}
