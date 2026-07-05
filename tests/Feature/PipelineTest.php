<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AppFactory;
use App\Support\ContainerFactory;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 미들웨어 파이프라인 통합 테스트.
 *
 * ErrorHandler → Cors → JwtAuth → RouteDispatch 전 구간을 실제 요청으로 관통시켜
 * 라우팅·인증·에러 변환이 표준 응답으로 이어지는지 검증한다. (DB·Redis 는 지연 연결이라
 * 이 경로들에서는 실제 연결이 일어나지 않는다.)
 */
final class PipelineTest extends TestCase
{
    private const string JWT_SECRET = 'test_secret_key_at_least_32_characters_long_xx';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['JWT_SECRET'] = self::JWT_SECRET;

        $this->container = ContainerFactory::build();
    }

    public function testHealthIsPublicAndReturnsOk(): void
    {
        $response = $this->handle('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('success', $body['status']);
        self::assertSame('ok', $body['data']['status']);
    }

    public function testUnknownRouteReturns404(): void
    {
        // 인증을 통과시킨 뒤라야 라우팅 단계까지 도달한다 (JwtAuth 가 RouteDispatch 보다 앞).
        $jwt = $this->issueToken(42, 900);
        $response = $this->handle('GET', '/api/v1/nope', ['Authorization' => "Bearer {$jwt}"]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $this->decode($response)['code']);
    }

    public function testProtectedRouteWithoutTokenReturns401(): void
    {
        $response = $this->handle('GET', '/api/v1/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->decode($response)['code']);
    }

    public function testRegisterRouteIsPublic(): void
    {
        // POST /api/v1/users 는 공개 → 인증을 거치지 않고 검증 단계까지 도달(빈 본문 → 422).
        $response = $this->handle('POST', '/api/v1/users');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->decode($response)['code']);
    }

    public function testUsersListRouteIsProtected(): void
    {
        // GET /api/v1/users 는 공개 목록이 아니므로(메서드 정확 매칭) 토큰 없이는 401.
        $response = $this->handle('GET', '/api/v1/users');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->decode($response)['code']);
    }

    public function testProtectedRouteWithMalformedTokenReturns401(): void
    {
        $response = $this->handle('GET', '/api/v1/me', ['Authorization' => 'Bearer not-a-jwt']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_TOKEN', $this->decode($response)['code']);
    }

    public function testProtectedRouteWithExpiredTokenReturns401(): void
    {
        $jwt = $this->issueToken(42, -10);
        $response = $this->handle('GET', '/api/v1/me', ['Authorization' => "Bearer {$jwt}"]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('TOKEN_EXPIRED', $this->decode($response)['code']);
    }

    public function testProtectedRouteWithValidTokenReturns200(): void
    {
        $jwt = $this->issueToken(42, 900);
        $response = $this->handle('GET', '/api/v1/me', ['Authorization' => "Bearer {$jwt}"]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(42, $this->decode($response)['data']['user_id']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(string $method, string $path, array $headers = []): ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return AppFactory::pipeline($this->container)->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function issueToken(int $userId, int $expiresInSeconds): string
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::JWT_SECRET));
        $now = new DateTimeImmutable();

        return $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify(sprintf('%+d seconds', $expiresInSeconds)))
            ->relatedTo((string) $userId)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }
}
