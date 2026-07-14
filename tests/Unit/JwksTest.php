<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\JwtAlgorithm;
use App\Support\Config;
use App\Support\Jwks;
use App\Support\JwtKeyGenerator;
use PHPUnit\Framework\TestCase;

/**
 * JWKS(공개키 자동 확인) 생성기 테스트.
 *
 * RS256 은 공개키를 JWK 로 노출하고(kid 는 RFC 7638 thumbprint), HS256 은 시크릿을 절대
 * 노출하지 않고 빈 키셋을 반환함을 검증한다.
 */
final class JwksTest extends TestCase
{
    private string $dir;
    private string $publicPath;
    private string $privatePath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aitessera_jwks_' . bin2hex(random_bytes(6));
        $this->privatePath = $this->dir . '/jwt_private.pem';
        $this->publicPath = $this->dir . '/jwt_public.pem';
        (new JwtKeyGenerator())->generate($this->privatePath, $this->publicPath, bits: 2048);
    }

    protected function tearDown(): void
    {
        foreach ([$this->privatePath, $this->publicPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testRs256KeySetExposesPublicKeyAsJwk(): void
    {
        $jwks = new Jwks($this->config(JwtAlgorithm::RS256));

        $keySet = $jwks->keySet();
        self::assertCount(1, $keySet['keys']);

        $jwk = $keySet['keys'][0];
        self::assertSame('RSA', $jwk['kty']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('RS256', $jwk['alg']);
        self::assertNotSame('', $jwk['kid']);

        // n·e 는 base64url(패딩·+·/ 없음)이어야 한다.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['n']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['e']);
        // RSA 공개 지수는 통상 65537 = base64url("AQAB").
        self::assertSame('AQAB', $jwk['e']);
    }

    public function testRs256KidMatchesRfc7638Thumbprint(): void
    {
        $jwks = new Jwks($this->config(JwtAlgorithm::RS256));
        $jwk = $jwks->keySet()['keys'][0];

        // RFC 7638: RSA 는 e·kty·n 을 사전순·무공백으로 직렬화한 SHA-256 을 base64url 로.
        $canonical = sprintf('{"e":"%s","kty":"RSA","n":"%s"}', $jwk['e'], $jwk['n']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');

        self::assertSame($expected, $jwk['kid']);
        self::assertSame($jwk['kid'], $jwks->signingKid());
    }

    public function testHs256YieldsEmptyKeySetAndNoKid(): void
    {
        $jwks = new Jwks($this->config(JwtAlgorithm::HS256));

        self::assertSame(['keys' => []], $jwks->keySet());
        self::assertNull($jwks->signingKid());
    }

    private function config(JwtAlgorithm $algo): Config
    {
        return new Config(
            appEnv: 'testing',
            appDebug: true,
            dbHost: '',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPass: '',
            redisHost: '',
            redisPort: 6379,
            jwtSecret: 'test_secret_key_at_least_32_characters_long_xx',
            jwtAlgo: $algo,
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
            jwtPrivateKeyPath: $this->privatePath,
            jwtPublicKeyPath: $this->publicPath,
        );
    }
}
