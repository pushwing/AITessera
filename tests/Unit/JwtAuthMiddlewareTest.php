<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\UserRole;
use App\Exception\InvalidTokenException;
use App\Exception\TokenExpiredException;
use App\Exception\UnauthorizedException;
use App\Middleware\JwtAuthMiddleware;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixedClock;

/**
 * JWT 검증 제약(서명 + 시간 클레임)을 검증한다.
 *
 * 특히 이슈 #45 — StrictValidAt 적용으로 iat(미래 발급)·nbf(미도래) 토큰이
 * 실제로 거부되는지, 만료/그 외 위반의 예외 매핑(TOKEN_EXPIRED vs INVALID_TOKEN)이
 * 회귀 없이 유지되는지 확인한다.
 */
final class JwtAuthMiddlewareTest extends TestCase
{
    private const string SECRET = 'test-secret-key-at-least-32-chars-long!!';
    private const string PROTECTED_PATH = '/api/v1/users/1';

    private Psr17Factory $psr17;
    private Configuration $jwtConfig;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::SECRET),
        );
        $this->now = new DateTimeImmutable('2026-07-08 12:00:00');
    }

    public function testValidTokenInjectsUserIdAndRole(): void
    {
        $token = $this->buildToken();
        $handler = $this->recordingHandler();

        $request = $this->psr17
            ->createServerRequest('GET', self::PROTECTED_PATH)
            ->withHeader('Authorization', 'Bearer ' . $token);
        $response = $this->middleware()->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        $captured = $handler->lastRequest();
        self::assertInstanceOf(ServerRequestInterface::class, $captured);
        self::assertSame(42, $captured->getAttribute('userId'));
        self::assertSame(UserRole::Member, $captured->getAttribute('role'));
    }

    public function testExpiredTokenThrowsTokenExpired(): void
    {
        // exp 가 현재보다 과거 — 만료.
        $token = $this->buildToken(expiresAt: $this->now->modify('-1 second'));

        $this->expectException(TokenExpiredException::class);
        $this->process($token);
    }

    public function testFutureIssuedAtThrowsInvalidToken(): void
    {
        // iat 가 미래 — StrictValidAt 이 거부해야 한다.
        $future = $this->now->modify('+1 hour');
        $token = $this->buildToken(issuedAt: $future, notBefore: $future, expiresAt: $future->modify('+15 minutes'));

        $this->expectException(InvalidTokenException::class);
        $this->process($token);
    }

    public function testNotYetValidNbfThrowsInvalidToken(): void
    {
        // nbf 가 미도래 — StrictValidAt 이 거부해야 한다. (iat·exp 는 유효)
        $token = $this->buildToken(notBefore: $this->now->modify('+1 hour'));

        $this->expectException(InvalidTokenException::class);
        $this->process($token);
    }

    public function testMissingNbfThrowsInvalidToken(): void
    {
        // StrictValidAt 은 nbf 존재를 요구한다 — 없으면 거부.
        $token = $this->buildToken(withNbf: false);

        $this->expectException(InvalidTokenException::class);
        $this->process($token);
    }

    public function testTamperedSignatureThrowsInvalidToken(): void
    {
        // 다른 키로 서명한 토큰 — SignedWith 가 거부.
        $foreign = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText('another-secret-key-at-least-32-chars!!'),
        );
        $token = $foreign->builder()
            ->issuedAt($this->now)
            ->canOnlyBeUsedAfter($this->now)
            ->expiresAt($this->now->modify('+15 minutes'))
            ->relatedTo('42')
            ->withClaim('role', UserRole::Member->value)
            ->getToken($foreign->signer(), $foreign->signingKey())
            ->toString();

        $this->expectException(InvalidTokenException::class);
        $this->process($token);
    }

    public function testMissingBearerThrowsUnauthorized(): void
    {
        $request = $this->psr17->createServerRequest('GET', self::PROTECTED_PATH);

        $this->expectException(UnauthorizedException::class);
        $this->middleware()->process($request, $this->recordingHandler());
    }

    private function buildToken(
        ?DateTimeImmutable $issuedAt = null,
        ?DateTimeImmutable $notBefore = null,
        ?DateTimeImmutable $expiresAt = null,
        bool $withNbf = true,
    ): string {
        $issuedAt ??= $this->now;
        $expiresAt ??= $this->now->modify('+15 minutes');

        $builder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->expiresAt($expiresAt)
            ->relatedTo('42')
            ->withClaim('role', UserRole::Member->value);

        if ($withNbf) {
            $builder = $builder->canOnlyBeUsedAfter($notBefore ?? $issuedAt);
        }

        return $builder
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())
            ->toString();
    }

    private function process(string $token): ResponseInterface
    {
        $request = $this->psr17
            ->createServerRequest('GET', self::PROTECTED_PATH)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $this->middleware()->process($request, $this->recordingHandler());
    }

    private function middleware(): JwtAuthMiddleware
    {
        return new JwtAuthMiddleware($this->jwtConfig, new FixedClock($this->now));
    }

    /**
     * 통과한 요청을 기록해 애트리뷰트 주입을 검증할 수 있게 하는 최종 핸들러.
     */
    private function recordingHandler(): RequestHandlerInterface&RecordingHandler
    {
        return new class ($this->psr17) implements RequestHandlerInterface, RecordingHandler {
            private ?ServerRequestInterface $lastRequest = null;

            public function __construct(private readonly Psr17Factory $psr17)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->lastRequest = $request;

                return $this->psr17->createResponse(200);
            }

            public function lastRequest(): ?ServerRequestInterface
            {
                return $this->lastRequest;
            }
        };
    }
}

/**
 * 최종 핸들러가 통과된 요청을 노출하기 위한 계약.
 */
interface RecordingHandler
{
    public function lastRequest(): ?ServerRequestInterface;
}
