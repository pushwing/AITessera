<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\JsonResponder;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * 클라이언트 IP 기반 레이트 리밋 (brute-force 방어).
 *
 * 파이프라인에서 Cors 다음, JwtAuth 앞에 위치한다. 인증 엔드포인트(로그인·가입 등)에는
 * 엄격한 정책을, 그 외 API 에는 완화된 정책을 적용한다. 헬스체크는 제외한다.
 * 초과 시 429 + `Retry-After` 헤더로 응답한다.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 엄격 정책을 적용할 인증 엔드포인트 — [메서드, 정확한 경로].
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array SENSITIVE_ROUTES = [
        ['POST', '/api/v1/tokens'],
        ['POST', '/api/v1/tokens/refresh'],
        ['POST', '/api/v1/users'],
        ['POST', '/api/v1/users/verify'],
        ['POST', '/api/v1/users/verify/resend'],
    ];

    /**
     * 레이트 리밋을 적용하지 않는 경로 (헬스 프로브 등).
     *
     * @var list<string>
     */
    private const array UNLIMITED_PATHS = [
        '/health',
    ];

    public function __construct(
        private JsonResponder $responder,
        private RateLimiterFactory $authLimiter,
        private RateLimiterFactory $apiLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (in_array($path, self::UNLIMITED_PATHS, true)) {
            return $handler->handle($request);
        }

        $factory = $this->isSensitive($request->getMethod(), $path) ? $this->authLimiter : $this->apiLimiter;
        $limit = $factory->create($this->clientIp($request))->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            return $this->responder
                ->error('RATE_LIMITED', '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.', 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        return $handler->handle($request);
    }

    private function isSensitive(string $method, string $path): bool
    {
        foreach (self::SENSITIVE_ROUTES as [$sensitiveMethod, $sensitivePath]) {
            if ($method === $sensitiveMethod && $path === $sensitivePath) {
                return true;
            }
        }

        return false;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        // REMOTE_ADDR 만 신뢰한다. 프록시(X-Forwarded-For) 신뢰는 별도 설정이 필요하므로 여기서는 사용하지 않는다.
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
