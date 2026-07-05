<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\InvalidTokenException;
use App\Exception\TokenExpiredException;
use App\Exception\UnauthorizedException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * `Authorization: Bearer` 토큰을 검증하고 사용자 ID 를 요청 애트리뷰트에 주입한다.
 *
 * 검증 제약: 서명(SignedWith, 알고리즘 고정) + 만료(isExpired) 를 모두 확인한다.
 * `alg:none` 우회는 SignedWith 가 서명 알고리즘을 지정된 signer 로 고정함으로써 차단된다.
 *
 * 공개 경로(PUBLIC_ROUTES)는 검증 없이 통과시킨다.
 */
final readonly class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * 인증 없이 접근 가능한 공개 경로 — [HTTP 메서드, 정확한 경로] 쌍.
     *
     * 메서드와 경로를 **정확히** 일치시킨다(접두어 매칭 금지). 같은 경로라도 다른 메서드는
     * 보호될 수 있고(예: `POST /api/v1/users`는 공개, `GET /api/v1/users`는 보호),
     * 하위 경로가 실수로 공개되지 않는다(예: 향후 `POST /api/v1/users/{id}/...`).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array PUBLIC_ROUTES = [
        ['GET', '/health'],
        ['POST', '/api/v1/tokens'],
        ['POST', '/api/v1/tokens/refresh'],
        ['DELETE', '/api/v1/tokens'],
        ['POST', '/api/v1/users'],
        ['POST', '/api/v1/users/verify'],
        ['POST', '/api/v1/users/verify/resend'],
        ['GET', '/api/docs'],
    ];

    public function __construct(
        private Configuration $jwtConfig,
        private ClockInterface $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isPublic($request->getMethod(), $request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $userId = $this->authenticate($request);
        $request = $request->withAttribute('userId', $userId);

        return $handler->handle($request);
    }

    private function isPublic(string $method, string $path): bool
    {
        foreach (self::PUBLIC_ROUTES as [$publicMethod, $publicPath]) {
            if ($method === $publicMethod && $path === $publicPath) {
                return true;
            }
        }

        return false;
    }

    private function authenticate(ServerRequestInterface $request): int
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedException('인증 토큰이 필요합니다.');
        }

        $jwt = substr($header, 7);
        if ($jwt === '') {
            throw new InvalidTokenException('토큰이 비어 있습니다.');
        }

        try {
            $token = $this->jwtConfig->parser()->parse($jwt);
        } catch (Throwable) {
            throw new InvalidTokenException();
        }

        if (!$token instanceof UnencryptedToken) {
            throw new InvalidTokenException();
        }

        try {
            $this->jwtConfig->validator()->assert(
                $token,
                new SignedWith($this->jwtConfig->signer(), $this->jwtConfig->verificationKey()),
            );
        } catch (RequiredConstraintsViolated) {
            throw new InvalidTokenException();
        }

        if ($token->isExpired($this->clock->now())) {
            throw new TokenExpiredException();
        }

        $sub = $token->claims()->get('sub');
        if (!is_numeric($sub)) {
            throw new InvalidTokenException('토큰에 유효한 사용자 식별자가 없습니다.');
        }

        return (int) $sub;
    }
}
