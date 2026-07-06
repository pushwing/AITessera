<?php

declare(strict_types=1);

namespace App\Support;

use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RouteDispatchMiddleware;
use App\Middleware\TrailingSlashMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Relay;

/**
 * PSR-15 미들웨어 파이프라인(Relay) 조립기.
 *
 * 요청 처리 순서를 한 곳에서 규정한다:
 *   ErrorHandler → TrailingSlash → Cors → RateLimit → JwtAuth → RouteDispatch
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
        TrailingSlashMiddleware::class,
        CorsMiddleware::class,
        RateLimitMiddleware::class,
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
