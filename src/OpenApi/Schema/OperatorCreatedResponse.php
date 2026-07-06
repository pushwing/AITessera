<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 운영자에 의한 계정 생성 성공 응답(이슈 #29).
 *
 * 생성 계정은 이메일 인증이 즉시 완료된 상태이므로 곧바로 로그인할 수 있다.
 */
#[OA\Schema(
    schema: 'OperatorCreatedResponse',
    description: '계정 생성 완료. 이메일 인증이 즉시 완료되어 로그인 가능하다.',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 2),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'agency@aivance.test'),
                new OA\Property(property: 'role', type: 'integer', enum: [1, 2], example: 2),
                new OA\Property(property: 'email_verified', type: 'boolean', example: true),
            ],
        ),
    ],
)]
final class OperatorCreatedResponse
{
}
