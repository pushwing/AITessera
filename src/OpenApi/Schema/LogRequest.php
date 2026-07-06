<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 클라이언트 로그 수집 요청 본문.
 */
#[OA\Schema(
    schema: 'LogRequest',
    description: '클라이언트(앱/웹) 로그 1건',
    required: ['level', 'message'],
    properties: [
        new OA\Property(property: 'level', type: 'string', description: 'PSR-3 로그 레벨', enum: ['debug', 'info', 'notice', 'warning', 'error', 'critical'], example: 'error'),
        new OA\Property(property: 'message', type: 'string', description: '로그 메시지', example: '결제 요청 실패'),
        new OA\Property(property: 'context', type: 'object', nullable: true, description: '부가 컨텍스트(자유 형식)', additionalProperties: true),
        new OA\Property(property: 'source', type: 'string', nullable: true, description: '발생 위치(앱/화면)', example: 'web-checkout'),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: '연관 사용자 id(선택)', example: 42),
        new OA\Property(property: 'logged_at', type: 'string', format: 'date-time', nullable: true, description: '클라이언트 발생 시각', example: '2026-07-06T09:00:00Z'),
    ],
)]
final class LogRequest
{
}
