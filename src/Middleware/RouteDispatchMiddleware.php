<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * 파이프라인 종단 미들웨어 — FastRoute 로 경로를 매칭하고 컨트롤러를 호출한다.
 *
 * 라우트 핸들러는 `[ControllerClass::class, 'method']` 형태로 정의하며,
 * 컨트롤러 인스턴스는 DI 컨테이너에서 해결한다. 경로 변수는 요청 애트리뷰트로 주입한다.
 */
final readonly class RouteDispatchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Dispatcher $dispatcher,
        private ContainerInterface $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeInfo = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());
        $status = $routeInfo[0] ?? Dispatcher::NOT_FOUND;

        return match ($status) {
            Dispatcher::NOT_FOUND => throw new NotFoundException(),
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowedException(),
            default => $this->invoke($routeInfo[1] ?? null, $routeInfo[2] ?? [], $request),
        };
    }

    private function invoke(mixed $handlerDef, mixed $vars, ServerRequestInterface $request): ResponseInterface
    {
        if (!is_array($handlerDef) || !isset($handlerDef[0], $handlerDef[1])) {
            throw new RuntimeException('라우트 핸들러 정의가 올바르지 않습니다.');
        }

        [$class, $method] = $handlerDef;
        if (!is_string($class) || !is_string($method)) {
            throw new RuntimeException('라우트 핸들러 정의가 올바르지 않습니다.');
        }

        if (is_array($vars)) {
            /** @var mixed $value */
            foreach ($vars as $name => $value) {
                if (is_string($name)) {
                    $request = $request->withAttribute($name, $value);
                }
            }
        }

        $controller = $this->container->get($class);
        if (!is_object($controller)) {
            throw new RuntimeException(sprintf('컨트롤러 %s 를 해결할 수 없습니다.', $class));
        }

        $callable = [$controller, $method];
        if (!is_callable($callable)) {
            throw new RuntimeException(sprintf('%s::%s() 를 호출할 수 없습니다.', $class, $method));
        }

        $response = $callable($request);
        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException(sprintf('%s::%s() 는 ResponseInterface 를 반환해야 합니다.', $class, $method));
        }

        return $response;
    }
}
