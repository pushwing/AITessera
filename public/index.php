<?php

declare(strict_types=1);

// AITessera 프론트 컨트롤러 — 유일한 웹 진입점(front controller).
//
// 처리 흐름:
//   .env 로딩 → DI 컨테이너 빌드 → PSR-15 미들웨어 파이프라인(Relay)
//   [ErrorHandler → Cors → JwtAuth → RouteDispatch] → PSR-7 응답 방출

use App\Support\AppFactory;
use App\Support\ContainerFactory;
use App\Support\ResponseEmitter;
use Dotenv\Dotenv;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. 환경변수 로딩
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// 2. DI 컨테이너 빌드
$container = ContainerFactory::build();

// 3. 전역 요청 → PSR-7 ServerRequest 생성
$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

// 4. 미들웨어 파이프라인 실행
$response = AppFactory::pipeline($container)->handle($request);

// 5. 응답 방출
(new ResponseEmitter())->emit($response);
