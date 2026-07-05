<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\MeController;
use FastRoute\RouteCollector;

/**
 * 라우트 정의 — FastRoute\simpleDispatcher 에 전달되는 콜백.
 *
 * 핸들러는 `[ControllerClass::class, 'method']` 형태로 지정한다.
 * 공개/보호 구분은 JwtAuthMiddleware 의 PUBLIC_PREFIXES 로 관리한다.
 */
return static function (RouteCollector $r): void {
    // 공개 — 헬스체크
    $r->addRoute('GET', '/health', [HealthController::class, 'index']);

    // 보호 — 현재 사용자 (JWT 필요)
    $r->addRoute('GET', '/api/v1/me', [MeController::class, 'show']);
};
