<?php

declare(strict_types=1);

namespace App;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 전역 정의 — swagger-php 가 src/ 스캔 시 수집하는 최상위 메타데이터.
 *
 * 개별 엔드포인트 어트리뷰트는 각 컨트롤러에 위치하며, 여기서는 Info·Server 와
 * 공용 보안 스킴(bearerAuth)만 선언한다.
 */
#[OA\Info(
    version: '0.1.0',
    title: 'AITessera API',
    description: 'AIvance 제품군을 위한 JWT 기반 인증·인가 API',
)]
#[OA\Server(url: '/', description: '현재 호스트')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
final class OpenApiSpec
{
}
