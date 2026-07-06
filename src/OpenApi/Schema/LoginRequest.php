<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 로그인 요청 본문.
 */
#[OA\Schema(
    schema: 'LoginRequest',
    description: '로그인 자격증명',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '가입한 이메일(로그인 ID)', example: 'admin@aivance.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '비밀번호', example: 'password1234!'),
    ],
)]
final class LoginRequest
{
}
