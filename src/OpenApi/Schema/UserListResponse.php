<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 운영자용 회원 목록 응답(이슈 #34).
 *
 * `meta` 는 페이지네이션 표준 4필드(page·per_page·total·last_page)를 포함한다.
 */
#[OA\Schema(
    schema: 'UserListResponse',
    description: '회원 목록(페이징). 목록 항목은 경량 필드만 포함한다.',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 10),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'member@aivance.test'),
                    new OA\Property(property: 'name', type: 'string', example: '홍길동'),
                    new OA\Property(property: 'affiliation', type: 'string', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
                    new OA\Property(property: 'role', type: 'integer', enum: [1, 2, 3], example: 3),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                    new OA\Property(property: 'email_verified', type: 'boolean', example: true),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-01 09:00:00'),
                ],
                type: 'object',
            ),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 20),
                new OA\Property(property: 'total', type: 'integer', example: 42),
                new OA\Property(property: 'last_page', type: 'integer', example: 3),
            ],
        ),
    ],
)]
final class UserListResponse
{
}
