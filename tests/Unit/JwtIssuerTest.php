<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Config;
use App\Support\JwtIssuer;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedClock;

final class JwtIssuerTest extends TestCase
{
    private const string JWT_SECRET = 'test_secret_key_at_least_32_characters_long_xx';

    private FixedClock $clock;
    private JwtIssuer $issuer;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-07-05 12:00:00'));
        $config = new Config(
            appEnv: 'testing',
            appDebug: true,
            dbHost: '',
            dbPort: 3306,
            dbName: '',
            dbUser: '',
            dbPass: '',
            redisHost: '',
            redisPort: 6379,
            jwtSecret: self::JWT_SECRET,
            jwtAlgo: 'HS256',
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            emailVerifyTtl: 86400,
            appBaseUrl: 'http://localhost:9300/',
        );
        $jwtConfig = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::JWT_SECRET));
        $this->issuer = new JwtIssuer($jwtConfig, $config, $this->clock);
    }

    public function testAccessTokenCarriesSubjectAndAffiliation(): void
    {
        $jwt = $this->issuer->issueAccessToken(42, 'aivance');

        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::JWT_SECRET));
        $token = $config->parser()->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);

        self::assertSame('42', $token->claims()->get('sub'));
        self::assertSame('aivance', $token->claims()->get('aff'));
    }

    public function testRefreshTokenHashIsDeterministicSha256(): void
    {
        self::assertSame(hash('sha256', 'abc'), $this->issuer->hashRefreshToken('abc'));
    }

    public function testGenerateRefreshTokenReturnsMatchingHashAndFutureExpiry(): void
    {
        $result = $this->issuer->generateRefreshToken();

        self::assertSame($this->issuer->hashRefreshToken($result['token']), $result['hash']);
        self::assertGreaterThan($this->clock->now(), $result['expiresAt']);
    }
}
