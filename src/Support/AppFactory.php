<?php

declare(strict_types=1);

namespace App\Support;

use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\RouteDispatchMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;

/**
 * PSR-15 미들웨어 파이프라인(Relay) 조립기.
 *
 * 요청 처리 순서를 한 곳에서 규정한다:
 *   ErrorHandler → Cors → JwtAuth → RouteDispatch
 *
 * TODO(rate-limit): brute-force 방어용 RateLimitMiddleware 는 로그인 엔드포인트 도입 시
 *   Cors 와 JwtAuth 사이에 추가한다 (symfony/rate-limiter + Redis 저장소).
 */
final class AppFactory
{
    /**
     * 파이프라인 순서대로 나열된 미들웨어 클래스. 컨테이너가 각 인스턴스를 생성·주입한다.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    private const array PIPELINE = [
        ErrorHandlerMiddleware::class,
        CorsMiddleware::class,
        JwtAuthMiddleware::class,
        RouteDispatchMiddleware::class,
    ];

    public static function pipeline(ContainerInterface $container): RequestHandlerInterface
    {
        $queue = [];
        foreach (self::PIPELINE as $middleware) {
            $resolved = $container->get($middleware);
            assert($resolved instanceof MiddlewareInterface);
            $queue[] = $resolved;
        }

        return new Relay($queue);
    }
}
