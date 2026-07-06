<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\UserRole;
use App\Exception\ForbiddenException;
use App\Middleware\RoleGuardMiddleware;
use App\Support\RequireRole;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 컨트롤러 액션의 `#[RequireRole]` 어트리뷰트를 리플렉션으로 읽어 회원구분을 검사하는지 검증한다.
 */
final class RoleGuardMiddlewareTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    public function testAllowsMatchingRole(): void
    {
        $response = $this->process('POST', '/operators', UserRole::Operator);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDeniesInsufficientRole(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->process('POST', '/operators', UserRole::Member);
    }

    public function testDeniesWhenRoleAttributeMissing(): void
    {
        // 공개 경로에 실수로 RequireRole 이 붙은 경우 등 — fail-closed.
        $this->expectException(ForbiddenException::class);
        $this->process('POST', '/operators', null);
    }

    public function testPassesThroughRouteWithoutGuard(): void
    {
        $response = $this->process('GET', '/open', null);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPassesThroughUnmatchedRoute(): void
    {
        // 매칭 실패는 RouteDispatchMiddleware 가 처리하도록 그대로 위임한다.
        $response = $this->process('GET', '/does-not-exist', null);

        self::assertSame(200, $response->getStatusCode());
    }

    private function process(string $method, string $path, ?UserRole $role): ResponseInterface
    {
        $middleware = new RoleGuardMiddleware($this->dispatcher());

        $request = $this->psr17->createServerRequest($method, $path);
        if ($role !== null) {
            $request = $request->withAttribute('role', $role);
        }

        return $middleware->process($request, $this->finalHandler());
    }

    private function dispatcher(): Dispatcher
    {
        return simpleDispatcher(static function (RouteCollector $r): void {
            $r->addRoute('POST', '/operators', [RoleGuardFixtureController::class, 'operatorOnly']);
            $r->addRoute('GET', '/open', [RoleGuardFixtureController::class, 'noGuard']);
        });
    }

    private function finalHandler(): RequestHandlerInterface
    {
        return new class ($this->psr17) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $psr17)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->psr17->createResponse(200);
            }
        };
    }
}

/**
 * 테스트 픽스처 — RoleGuard 가 리플렉션으로 읽을 어트리뷰트만 필요하다(본문은 호출되지 않음).
 */
final class RoleGuardFixtureController
{
    #[RequireRole(UserRole::Operator)]
    public function operatorOnly(ServerRequestInterface $request): ResponseInterface
    {
        return (new Psr17Factory())->createResponse(200);
    }

    public function noGuard(ServerRequestInterface $request): ResponseInterface
    {
        return (new Psr17Factory())->createResponse(200);
    }
}
