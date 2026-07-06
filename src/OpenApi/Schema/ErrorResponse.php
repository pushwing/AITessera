<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 표준 에러 응답. 모든 엔드포인트의 4xx/5xx 응답이 참조한다.
 *
 * 문서 전용 스키마 클래스(런타임 로직 없음). swagger-php 가 `src/` 스캔 시 수집한다.
 */
#[OA\Schema(
    schema: 'ErrorResponse',
    description: '표준 에러 응답 포맷',
    required: ['status', 'code', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['error'], example: 'error'),
        new OA\Property(
            property: 'code',
            type: 'string',
            description: '기계가 분기하는 에러 코드',
            enum: [
                'UNAUTHORIZED',
                'INVALID_TOKEN',
                'TOKEN_EXPIRED',
                'INVALID_CREDENTIALS',
                'EMAIL_NOT_VERIFIED',
                'VALIDATION_ERROR',
                'ALREADY_EXISTS',
                'NOT_FOUND',
                'METHOD_NOT_ALLOWED',
                'RATE_LIMITED',
                'INTERNAL_ERROR',
            ],
            example: 'VALIDATION_ERROR',
        ),
        new OA\Property(property: 'message', type: 'string', description: '사람이 읽는 메시지', example: '입력값 검증에 실패했습니다.'),
    ],
)]
final class ErrorResponse
{
}
