<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\UserRole;
use App\Exception\InvalidTokenException;
use App\Exception\TokenExpiredException;
use App\Exception\UnauthorizedException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * `Authorization: Bearer` 토큰을 검증하고 사용자 ID·회원구분을 요청 애트리뷰트에 주입한다.
 *
 * 검증 제약: 서명(SignedWith, 알고리즘 고정) + 시간 클레임(StrictValidAt: iat·nbf·exp) 을 모두 확인한다.
 * `alg:none` 우회는 SignedWith 가 서명 알고리즘을 지정된 signer 로 고정함으로써 차단된다.
 * 만료(exp)는 TOKEN_EXPIRED 로 별도 매핑하기 위해 StrictValidAt 이전에 isExpired 로 먼저 확인한다.
 *
 * 주입 애트리뷰트: `userId`(int), `role`(UserRole). `role` 은 인가(RoleGuardMiddleware)의 기준값이다.
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
        ['POST', '/api/v1/logs'],
        ['GET', '/api/docs'],
        ['GET', '/api/v1/openapi.json'],
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

        $auth = $this->authenticate($request);
        $request = $request
            ->withAttribute('userId', $auth['userId'])
            ->withAttribute('role', $auth['role']);

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

    /**
     * @return array{userId: int, role: UserRole}
     */
    private function authenticate(ServerRequestInterface $request): array
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

        // 만료(exp)는 TOKEN_EXPIRED 로 구분 매핑하기 위해 먼저 확인한다.
        // (StrictValidAt 도 exp 를 검사하지만 위반 종류를 구분해 던지지 않으므로 분리한다.)
        if ($token->isExpired($this->clock->now())) {
            throw new TokenExpiredException();
        }

        // iat(미래 발급)·nbf(미도래) 시간 클레임을 lcobucci 표준 제약으로 검증한다.
        // StrictValidAt 은 iat·nbf·exp 존재를 모두 요구한다(발급기 JwtIssuer 가 셋 다 설정).
        try {
            $this->jwtConfig->validator()->assert(
                $token,
                new StrictValidAt($this->clock),
            );
        } catch (RequiredConstraintsViolated) {
            throw new InvalidTokenException();
        }

        $sub = $token->claims()->get('sub');
        if (!is_numeric($sub)) {
            throw new InvalidTokenException('토큰에 유효한 사용자 식별자가 없습니다.');
        }

        $roleClaim = $token->claims()->get('role');
        $role = is_numeric($roleClaim) ? UserRole::tryFrom((int) $roleClaim) : null;
        if ($role === null) {
            throw new InvalidTokenException('토큰에 유효한 회원구분이 없습니다.');
        }

        return ['userId' => (int) $sub, 'role' => $role];
    }
}
