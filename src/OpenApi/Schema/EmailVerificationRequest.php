<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 이메일 인증 요청 본문.
 */
#[OA\Schema(
    schema: 'EmailVerificationRequest',
    required: ['token'],
    properties: [
        new OA\Property(
            property: 'token',
            type: 'string',
            description: '회원가입 시 이메일로 발송된 **이메일 인증 토큰**(64자 hex). '
                . '로그인 응답의 Access 토큰(JWT `eyJ...`)이 아닙니다. '
                . 'dev 환경에서는 var/logs/mail-*.log 의 인증 링크(?token=...)에서 확인할 수 있습니다.',
            example: 'f9ea9eabf2a507f671e1d69b3c2a8d40e1b7c6a9f0d3e2b1a4c5d6e7f8091a2b',
        ),
    ],
)]
final class EmailVerificationRequest
{
}
