<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AppFactory;
use App\Support\ContainerFactory;
use App\Support\JwtKeyGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * JWKS 엔드포인트 통합 테스트 (RS256 전체 파이프라인).
 *
 * 런타임에 RSA 키페어를 생성해 JWT_ALGO=RS256 으로 컨테이너를 구성하고, 표준·버전 경로 모두
 * 인증 없이 공개키(JWK)를 노출하는지 검증한다. `$_ENV` 는 setUp 에서 스냅샷 후 복원한다.
 */
final class JwksEndpointTest extends TestCase
{
    private string $dir;
    private string $privatePath;
    private string $publicPath;

    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;

        $this->dir = sys_get_temp_dir() . '/aitessera_jwks_ep_' . bin2hex(random_bytes(6));
        $this->privatePath = $this->dir . '/jwt_private.pem';
        $this->publicPath = $this->dir . '/jwt_public.pem';
        (new JwtKeyGenerator())->generate($this->privatePath, $this->publicPath, bits: 2048);

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['JWT_ALGO'] = 'RS256';
        $_ENV['JWT_PRIVATE_KEY_PATH'] = $this->privatePath;
        $_ENV['JWT_PUBLIC_KEY_PATH'] = $this->publicPath;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;

        foreach ([$this->privatePath, $this->publicPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testBothPathsExposeRs256PublicKey(): void
    {
        $container = ContainerFactory::build();

        foreach (['/.well-known/jwks.json', '/api/v1/jwks.json'] as $path) {
            $response = $this->handle($container, $path);

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringContainsString('application/jwk-set+json', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('max-age', $response->getHeaderLine('Cache-Control'));

            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertArrayHasKey('keys', $body);
            self::assertIsArray($body['keys']);
            self::assertCount(1, $body['keys'], $path);

            $jwk = $body['keys'][0];
            self::assertSame('RSA', $jwk['kty']);
            self::assertSame('sig', $jwk['use']);
            self::assertSame('RS256', $jwk['alg']);
            self::assertNotSame('', $jwk['kid']);
            self::assertSame('AQAB', $jwk['e']);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['n']);
        }
    }

    private function handle(ContainerInterface $container, string $path): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $path);

        return AppFactory::pipeline($container)->handle($request);
    }
}
