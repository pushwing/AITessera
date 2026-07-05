<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\SystemClock;

use function DI\autowire;
use function DI\factory;
use function DI\get;

use FastRoute\Dispatcher;
use Lcobucci\JWT\Configuration as JwtConfiguration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Predis\Client as RedisClient;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

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

    // PSR-17 팩토리 — 하나의 nyholm 인스턴스를 여러 인터페이스에 바인딩
    Psr17Factory::class => autowire(),
    ResponseFactoryInterface::class => get(Psr17Factory::class),
    StreamFactoryInterface::class => get(Psr17Factory::class),

    // PDO — 지연 연결 (첫 사용 시점에 생성)
    PDO::class => factory(static function (Config $config): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );

        return new PDO($dsn, $config->dbUser, $config->dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }),

    // Redis (predis) — 캐시·큐 공용
    RedisClient::class => factory(static function (Config $config): RedisClient {
        return new RedisClient([
            'scheme' => 'tcp',
            'host' => $config->redisHost,
            'port' => $config->redisPort,
        ]);
    }),

    // JWT (lcobucci) — HS256 대칭키 서명 설정
    JwtConfiguration::class => factory(static function (Config $config): JwtConfiguration {
        return JwtConfiguration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($config->jwtSecret),
        );
    }),

    // FastRoute 디스패처 — 라우트 정의를 컴파일
    Dispatcher::class => factory(static function (): Dispatcher {
        /** @var callable(\FastRoute\RouteCollector): void $routes */
        $routes = require __DIR__ . '/routes.php';

        return FastRoute\simpleDispatcher($routes);
    }),
];
