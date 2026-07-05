<?php

declare(strict_types=1);

// AITessera 프론트 컨트롤러 — 유일한 웹 진입점(front controller).
//
// TODO(부트스트랩): 다음 작업에서 아래 파이프라인을 연결한다.
//   .env 로딩 → DI 컨테이너 빌드 → PSR-15 미들웨어 파이프라인(Relay)
//   [ErrorHandler → Cors → RateLimit → JwtAuth → RouteDispatch]
// 현재는 스캐폴딩 확인용 헬스 응답만 반환한다.

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'success',
    'data'   => [
        'service' => 'AITessera',
        'message' => 'bootstrap pending',
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
