<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 인증 메일 재발송 요청 본문.
 */
#[OA\Schema(
    schema: 'EmailResendRequest',
    required: ['email'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '인증 메일을 재발송할 가입 이메일', example: 'user@aivance.test'),
    ],
)]
final class EmailResendRequest
{
}
