<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 현재 로그인 사용자 프로필 응답.
 */
#[OA\Schema(
    schema: 'MeResponse',
    description: '현재 로그인 사용자 정보(민감정보 제외)',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@aivance.test'),
                new OA\Property(property: 'name', type: 'string', example: '관리자'),
                new OA\Property(property: 'affiliation', type: 'string', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
                new OA\Property(property: 'contact', type: 'string', example: '010-1234-5678'),
                new OA\Property(property: 'company', type: 'string', nullable: true, example: 'AIvance'),
                new OA\Property(property: 'profile', type: 'object', nullable: true, description: '소속별 부가 항목', additionalProperties: true),
                new OA\Property(property: 'email_verified', type: 'boolean', example: true),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-06 09:00:00'),
            ],
        ),
    ],
)]
final class MeResponse
{
}
