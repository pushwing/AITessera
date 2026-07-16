<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 인증 메일 재발송 요청 본문.
 */
#[OA\Schema(
    schema: 'EmailResendRequest',
    required: ['email', 'affiliation'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '인증 메일을 재발송할 가입 이메일', example: 'user@aivance.test'),
        new OA\Property(property: 'affiliation', type: 'string', description: '가입한 소속 서비스 — 동일 이메일이 여러 소속에 가입돼 있을 수 있어 필수', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
    ],
)]
final class EmailResendRequest
{
}
