<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\JwtAlgorithm;
use App\Domain\UserRole;
use App\Support\Config;
use App\Support\JwtIssuer;
use App\Support\JwtKeyGenerator;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

/**
 * RS256(비대칭키) 발급→검증 왕복 테스트.
 *
 * 키페어를 런타임에 생성하므로 외부 키 파일·CI 시크릿 없이 자체 완결적으로 동작한다.
 * `JwtIssuer`·검증 로직이 알고리즘에 비의존적임을(HS256 코드 변경 없이 RS256 동작) 입증한다.
 */
final class JwtRs256RoundTripTest extends TestCase
{
    private string $dir;
    private string $privatePath;
    private string $publicPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aitessera_rs256_' . bin2hex(random_bytes(6));
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

    private function config(): Config
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
            jwtSecret: '',
            jwtAlgo: JwtAlgorithm::RS256,
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
            jwtPrivateKeyPath: $this->privatePath,
            jwtPublicKeyPath: $this->publicPath,
        );
    }

    private function asymmetricConfiguration(): Configuration
    {
        return Configuration::forAsymmetricSigner(
            new RsaSha256(),
            InMemory::file($this->privatePath),
            InMemory::file($this->publicPath),
        );
    }

    public function testAccessTokenIsSignedWithRs256AndVerifiesWithPublicKey(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-05 12:00:00'));
        $jwtConfig = $this->asymmetricConfiguration();
        $issuer = new JwtIssuer($jwtConfig, $this->config(), $clock);

        $jwt = $issuer->issueAccessToken(42, 'aivance', UserRole::Operator);

        $token = $jwtConfig->parser()->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);

        // 토큰 헤더의 알고리즘이 RS256 으로 고정되었는지 확인.
        self::assertSame('RS256', $token->headers()->get('alg'));

        // 공개키로 서명 검증이 통과해야 한다.
        $jwtConfig->validator()->assert(
            $token,
            new SignedWith($jwtConfig->signer(), $jwtConfig->verificationKey()),
        );

        self::assertSame('42', $token->claims()->get('sub'));
        self::assertSame('aivance', $token->claims()->get('aff'));
        self::assertSame(UserRole::Operator->value, (int) $token->claims()->get('role'));
    }

    public function testTokenSignedWithForeignKeyFailsVerification(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-05 12:00:00'));

        // 다른 키페어로 서명한 토큰은 이 서버의 공개키로 검증되면 안 된다(위조 방어).
        $foreignDir = sys_get_temp_dir() . '/aitessera_rs256_foreign_' . bin2hex(random_bytes(6));
        $foreignPrivate = $foreignDir . '/jwt_private.pem';
        $foreignPublic = $foreignDir . '/jwt_public.pem';
        (new JwtKeyGenerator())->generate($foreignPrivate, $foreignPublic, bits: 2048);

        try {
            $foreignConfig = Configuration::forAsymmetricSigner(
                new RsaSha256(),
                InMemory::file($foreignPrivate),
                InMemory::file($foreignPublic),
            );
            $foreignIssuer = new JwtIssuer($foreignConfig, $this->config(), $clock);
            $jwt = $foreignIssuer->issueAccessToken(42, 'aivance', UserRole::Operator);

            $jwtConfig = $this->asymmetricConfiguration();
            $token = $jwtConfig->parser()->parse($jwt);

            $this->expectException(RequiredConstraintsViolated::class);
            $jwtConfig->validator()->assert(
                $token,
                new SignedWith($jwtConfig->signer(), $jwtConfig->verificationKey()),
            );
        } finally {
            foreach ([$foreignPrivate, $foreignPublic] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($foreignDir)) {
                rmdir($foreignDir);
            }
        }
    }
}
