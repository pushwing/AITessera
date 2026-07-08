<?php

declare(strict_types=1);

use App\Controller\AdminUserController;
use App\Controller\DocsController;
use App\Controller\HealthController;
use App\Controller\JwksController;
use App\Controller\LogController;
use App\Controller\MeController;
use App\Controller\OperatorController;
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

    // 공개 — JWKS (RS256 공개키 자동 확인): 표준 well-known 경로 + 버전 경로 별칭
    $r->addRoute('GET', '/.well-known/jwks.json', [JwksController::class, 'wellKnown']);
    $r->addRoute('GET', '/api/v1/jwks.json', [JwksController::class, 'versioned']);

    // 공개 — 인증 토큰
    $r->addRoute('POST', '/api/v1/tokens', [TokenController::class, 'issue']);
    $r->addRoute('POST', '/api/v1/tokens/refresh', [TokenController::class, 'refresh']);
    $r->addRoute('DELETE', '/api/v1/tokens', [TokenController::class, 'revoke']);

    // 공개 — 회원가입·이메일 인증
    $r->addRoute('POST', '/api/v1/users', [UserController::class, 'register']);
    $r->addRoute('POST', '/api/v1/users/verify', [UserController::class, 'verify']);
    $r->addRoute('POST', '/api/v1/users/verify/resend', [UserController::class, 'resend']);

    // 공개 — 클라이언트 로그 수집 (비동기 큐 적재)
    $r->addRoute('POST', '/api/v1/logs', [LogController::class, 'store']);

    // 보호 — 현재 사용자 (JWT 필요): 조회·본인 수정·탈퇴
    $r->addRoute('GET', '/api/v1/me', [MeController::class, 'show']);
    $r->addRoute('PATCH', '/api/v1/me', [MeController::class, 'update']);
    $r->addRoute('DELETE', '/api/v1/me', [MeController::class, 'destroy']);

    // 보호 — 운영자 전용: 운영자·대행사·일반회원 계정 생성 (#[RequireRole(Operator)])
    $r->addRoute('POST', '/api/v1/operators', [OperatorController::class, 'create']);

    // 보호 — 운영자 전용: 회원 관리 (목록·상세·수정) (#[RequireRole(Operator)])
    $r->addRoute('GET', '/api/v1/users', [AdminUserController::class, 'index']);
    $r->addRoute('GET', '/api/v1/users/{id:\d+}', [AdminUserController::class, 'show']);
    $r->addRoute('PATCH', '/api/v1/users/{id:\d+}', [AdminUserController::class, 'update']);
};
