<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 토큰 발급/재발급 성공 응답.
 */
#[OA\Schema(
    schema: 'TokenResponse',
    description: '로그인·재발급 성공 시 Access/Refresh 토큰 쌍',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success'], example: 'success'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'access_token', type: 'string', description: '보호 API 호출용 JWT(15분). `Authorization: Bearer <access_token>` 로 사용.'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'expires_in', type: 'integer', description: 'Access 토큰 만료(초)', example: 900),
                new OA\Property(property: 'refresh_token', type: 'string', description: '재발급(회전)·로그아웃용 불투명 토큰(14일). 이메일 인증 토큰과 무관.'),
            ],
        ),
    ],
)]
final class TokenResponse
{
}
