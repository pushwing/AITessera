<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 경로 끝의 슬래시를 제거해 이후 미들웨어·라우팅이 일관된 경로를 보게 한다.
 *
 * 예: `/api/docs/` → `/api/docs`. 리다이렉트 없이 내부적으로 다시 쓰므로 본문이 있는
 * 요청(POST 등)도 그대로 처리된다. 루트(`/`)는 그대로 둔다.
 */
final readonly class TrailingSlashMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $normalized = rtrim($path, '/');
            $request = $request->withUri($uri->withPath($normalized === '' ? '/' : $normalized));
        }

        return $handler->handle($request);
    }
}
