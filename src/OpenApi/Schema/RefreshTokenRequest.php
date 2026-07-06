<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — Refresh 토큰 본문(재발급·로그아웃 공용).
 */
#[OA\Schema(
    schema: 'RefreshTokenRequest',
    required: ['refresh_token'],
    properties: [
        new OA\Property(
            property: 'refresh_token',
            type: 'string',
            description: '로그인/재발급 응답으로 받은 **Refresh 토큰**(불투명 문자열). '
                . '로그인 Access 토큰(JWT)이 아니며, Authorization 헤더에도 넣지 않습니다.',
            example: '3f9a1c8e2b7d…(64자 hex)',
        ),
    ],
)]
final class RefreshTokenRequest
{
}
