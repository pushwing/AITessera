<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\UserRole;
use App\Exception\ForbiddenException;
use App\Support\RequireRole;
use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;

/**
 * 회원구분 기반 인가 미들웨어.
 *
 * 라우트로 매칭된 컨트롤러 메서드에 `#[RequireRole]` 이 붙어 있으면, 그 요구 구분과
 * 요청의 `role` 애트리뷰트(JwtAuthMiddleware 가 주입)를 대조한다. 불일치하거나 애트리뷰트가
 * 없으면(공개 경로에 실수로 RequireRole 을 붙인 경우 등) `ForbiddenException` 으로 fail-closed.
 *
 * 파이프라인상 JwtAuthMiddleware 다음, RouteDispatchMiddleware 앞에 위치한다. 라우트 매칭은
 * FastRoute 로 저렴하므로(O(1)) RouteDispatch 와 중복 매칭 비용은 무시할 수 있다.
 */
final readonly class RoleGuardMiddleware implements MiddlewareInterface
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeInfo = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());
        if (($routeInfo[0] ?? Dispatcher::NOT_FOUND) !== Dispatcher::FOUND) {
            // 매칭 실패·메서드 불허는 RouteDispatchMiddleware 가 표준 예외로 처리한다.
            return $handler->handle($request);
        }

        $required = $this->requiredRoles($routeInfo[1] ?? null);
        if ($required === []) {
            return $handler->handle($request);
        }

        $role = $request->getAttribute('role');
        if (!$role instanceof UserRole || !in_array($role, $required, true)) {
            throw new ForbiddenException();
        }

        return $handler->handle($request);
    }

    /**
     * 매칭된 핸들러 메서드의 `#[RequireRole]` 이 요구하는 구분 목록을 반환한다(없으면 빈 배열).
     *
     * @return list<UserRole>
     */
    private function requiredRoles(mixed $handlerDef): array
    {
        if (
            !is_array($handlerDef)
            || !isset($handlerDef[0], $handlerDef[1])
            || !is_string($handlerDef[0])
            || !is_string($handlerDef[1])
            || !method_exists($handlerDef[0], $handlerDef[1])
        ) {
            return [];
        }

        $attributes = (new ReflectionMethod($handlerDef[0], $handlerDef[1]))->getAttributes(RequireRole::class);
        if ($attributes === []) {
            return [];
        }

        return $attributes[0]->newInstance()->roles;
    }
}
