<?php

declare(strict_types=1);

use App\Domain\JwtAlgorithm;
use App\Middleware\RateLimitMiddleware;
use App\Repository\EmailVerificationRepository;
use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\LogRepository;
use App\Repository\LogRepositoryInterface;
use App\Repository\RefreshTokenRepository;
use App\Repository\RefreshTokenRepositoryInterface;
use App\Repository\UserRepository;
use App\Repository\UserRepositoryInterface;
use App\Support\Ai\ClaudeLogAiClassifier;
use App\Support\Ai\ClaudeLogReportWriter;
use App\Support\Ai\LogAiClassifierInterface;
use App\Support\Ai\LogReportWriterInterface;
use App\Support\Ai\NullLogAiClassifier;
use App\Support\Ai\NullLogReportWriter;
use App\Support\Config;
use App\Support\ConnectionInterface;
use App\Support\Database;
use App\Support\Mail\LogMailer;
use App\Support\Mail\MailerInterface;
use App\Support\Queue\InMemoryQueue;
use App\Support\Queue\QueueInterface;
use App\Support\Queue\RedisQueue;
use App\Support\SystemClock;

use function DI\autowire;
use function DI\factory;
use function DI\get;

use FastRoute\Dispatcher;
use Lcobucci\JWT\Configuration as JwtConfiguration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Nyholm\Psr7\Factory\Psr17Factory;
use Predis\Client as RedisClient;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * DI 컨테이너 정의 (PHP-DI).
 *
 * 컨트롤러·미들웨어·Service·Repository 는 오토와이어링으로 자동 해결되므로
 * 여기에는 인터페이스 바인딩과 팩토리(외부 리소스 연결)만 명시한다.
 */
return [
    // 설정 — .env → readonly DTO
    Config::class => factory(static fn (): Config => Config::fromEnv()),

    // 시계 (PSR-20)
    ClockInterface::class => autowire(SystemClock::class),

    // DB 연결 — 지연 연결 래퍼
    ConnectionInterface::class => autowire(Database::class),

    // Repository — 인터페이스 → PDO 구현 바인딩
    UserRepositoryInterface::class => autowire(UserRepository::class),
    RefreshTokenRepositoryInterface::class => autowire(RefreshTokenRepository::class),
    EmailVerificationRepositoryInterface::class => autowire(EmailVerificationRepository::class),
    LogRepositoryInterface::class => autowire(LogRepository::class),

    // 큐 — 테스트는 인메모리(무-Redis), 그 외 Redis 리스트
    QueueInterface::class => factory(static function (Config $config, RedisClient $redis): QueueInterface {
        return $config->appEnv === 'testing' ? new InMemoryQueue() : new RedisQueue($redis);
    }),

    // 메일러 — 개발용 로그 구현 (운영은 symfony/mailer SMTP 로 교체)
    MailerInterface::class => autowire(LogMailer::class),

    // 로그 AI 분류기 — 테스트이거나 API 키가 없으면 무동작(Null), 그 외 Claude API 구현.
    // 이렇게 두면 CI·로컬은 외부 호출 없이 동작하고, 운영은 키만 주입하면 실제 분류가 켜진다.
    LogAiClassifierInterface::class => factory(static function (Config $config): LogAiClassifierInterface {
        if ($config->appEnv === 'testing' || $config->anthropicApiKey === '') {
            return new NullLogAiClassifier();
        }

        return new ClaudeLogAiClassifier($config->anthropicApiKey, $config->anthropicModel, $config->aiTimeout);
    }),

    // 일일 로그 리포트 작성기 — 테스트이거나 API 키가 없으면 무동작(Null), 그 외 Claude API 구현.
    // Null 을 받으면 워커가 통계 기반 폴백 리포트로 발송한다(외부 호출 없이도 파이프라인 동작).
    LogReportWriterInterface::class => factory(static function (Config $config): LogReportWriterInterface {
        if ($config->appEnv === 'testing' || $config->anthropicApiKey === '') {
            return new NullLogReportWriter();
        }

        return new ClaudeLogReportWriter($config->anthropicApiKey, $config->anthropicModel, $config->aiTimeout);
    }),

    // 레이트 리밋 저장소 — 테스트는 인메모리(무-Redis), 그 외 Redis 캐시
    StorageInterface::class => factory(static function (Config $config, RedisClient $redis): StorageInterface {
        if ($config->appEnv === 'testing') {
            return new InMemoryStorage();
        }

        return new CacheStorage(new RedisAdapter($redis, 'ratelimit'));
    }),

    // 레이트 리밋 정책 — 인증(엄격) / 일반 API
    'rate_limiter.auth' => factory(static fn (Config $config, StorageInterface $storage): RateLimiterFactory => new RateLimiterFactory([
        'id' => 'auth',
        'policy' => 'sliding_window',
        'limit' => $config->rateLimitAuth,
        'interval' => '1 minute',
    ], $storage)),
    'rate_limiter.api' => factory(static fn (Config $config, StorageInterface $storage): RateLimiterFactory => new RateLimiterFactory([
        'id' => 'api',
        'policy' => 'sliding_window',
        'limit' => $config->rateLimitApi,
        'interval' => '1 minute',
    ], $storage)),

    RateLimitMiddleware::class => autowire()
        ->constructorParameter('authLimiter', get('rate_limiter.auth'))
        ->constructorParameter('apiLimiter', get('rate_limiter.api')),

    // PSR-17 팩토리 — 하나의 nyholm 인스턴스를 여러 인터페이스에 바인딩
    Psr17Factory::class => autowire(),
    ResponseFactoryInterface::class => get(Psr17Factory::class),
    StreamFactoryInterface::class => get(Psr17Factory::class),

    // Redis (predis) — 캐시·큐 공용
    RedisClient::class => factory(static function (Config $config): RedisClient {
        return new RedisClient([
            'scheme' => 'tcp',
            'host' => $config->redisHost,
            'port' => $config->redisPort,
        ]);
    }),

    // JWT (lcobucci) — 알고리즘별 서명 설정. 발급(JwtIssuer)·검증(JwtAuthMiddleware) 이
    // 이 단일 Configuration 을 공유하므로 키/알고리즘 불일치가 구조적으로 차단된다.
    //
    // Config 는 알고리즘에 따라 시크릿/키 경로가 빈 문자열일 수 있어(HS256 이면 키 경로 '',
    // RS256 이면 secret '') 프로퍼티 타입이 일반 string 이다. InMemory 는 non-empty-string 을
    // 요구하므로, 각 알고리즘 분기에서 실제로 필요한 값이 비어 있지 않음을 가드로 다시 확인해
    // 타입을 좁힌다(Config::fromEnv() 의 fail-fast 를 DI 경계에서 방어적으로 재확인).
    JwtConfiguration::class => factory(static function (Config $config): JwtConfiguration {
        if ($config->jwtAlgo === JwtAlgorithm::HS256) {
            // HS256: 대칭키(HMAC) — 서명·검증 동일 키
            $secret = $config->jwtSecret;
            if ($secret === '') {
                throw new RuntimeException('HS256 서명에는 JWT_SECRET 이 필요합니다.');
            }

            return JwtConfiguration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        }

        // RS256: 비대칭키(RSA) — 개인키로 서명, 공개키로 검증
        $privateKeyPath = $config->jwtPrivateKeyPath;
        $publicKeyPath = $config->jwtPublicKeyPath;
        if ($privateKeyPath === '' || $publicKeyPath === '') {
            throw new RuntimeException('RS256 서명에는 JWT_PRIVATE_KEY_PATH·JWT_PUBLIC_KEY_PATH 가 필요합니다.');
        }

        return JwtConfiguration::forAsymmetricSigner(
            new RsaSha256(),
            InMemory::file($privateKeyPath, $config->jwtPrivateKeyPassphrase),
            InMemory::file($publicKeyPath),
        );
    }),

    // FastRoute 디스패처 — 라우트 정의를 컴파일
    Dispatcher::class => factory(static function (): Dispatcher {
        /** @var callable(\FastRoute\RouteCollector): void $routes */
        $routes = require __DIR__ . '/routes.php';

        return FastRoute\simpleDispatcher($routes);
    }),
];
