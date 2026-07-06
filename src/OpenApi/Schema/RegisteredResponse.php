<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 회원가입 성공 응답(토큰 미발급, 이메일 인증 대기).
 */
#[OA\Schema(
    schema: 'RegisteredResponse',
    description: '가입 완료. 토큰은 발급되지 않으며, 이메일 인증 후 로그인한다.',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@aivance.test'),
                new OA\Property(property: 'email_verified', type: 'boolean', example: false),
            ],
        ),
    ],
)]
final class RegisteredResponse
{
}
