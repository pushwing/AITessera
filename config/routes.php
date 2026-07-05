<?php

declare(strict_types=1);

use App\Controller\DocsController;
use App\Controller\HealthController;
use App\Controller\MeController;
use App\Controller\TokenController;
use App\Controller\UserController;
use FastRoute\RouteCollector;

/**
 * 라우트 정의 — FastRoute\simpleDispatcher 에 전달되는 콜백.
 *
 * 핸들러는 `[ControllerClass::class, 'method']` 형태로 지정한다.
 * 공개/보호 구분은 JwtAuthMiddleware 의 PUBLIC_ROUTES 로 관리한다.
 */
return static function (RouteCollector $r): void {
    // 공개 — 헬스체크
    $r->addRoute('GET', '/health', [HealthController::class, 'index']);

    // 공개 — API 문서 (Swagger UI + OpenAPI 스펙)
    $r->addRoute('GET', '/api/docs', [DocsController::class, 'ui']);
    $r->addRoute('GET', '/api/v1/openapi.json', [DocsController::class, 'spec']);

    // 공개 — 인증 토큰
    $r->addRoute('POST', '/api/v1/tokens', [TokenController::class, 'issue']);
    $r->addRoute('POST', '/api/v1/tokens/refresh', [TokenController::class, 'refresh']);
    $r->addRoute('DELETE', '/api/v1/tokens', [TokenController::class, 'revoke']);

    // 공개 — 회원가입·이메일 인증
    $r->addRoute('POST', '/api/v1/users', [UserController::class, 'register']);
    $r->addRoute('POST', '/api/v1/users/verify', [UserController::class, 'verify']);
    $r->addRoute('POST', '/api/v1/users/verify/resend', [UserController::class, 'resend']);

    // 보호 — 현재 사용자 (JWT 필요)
    $r->addRoute('GET', '/api/v1/me', [MeController::class, 'show']);
};
