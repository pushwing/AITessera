<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\JwtAlgorithm;
use RuntimeException;

/**
 * 애플리케이션 설정 — `.env` 환경변수를 타입 안전한 readonly DTO 로 매핑한다.
 *
 * 원시 `$_ENV` 를 코드 전역에서 직접 읽지 않고, 반드시 이 객체를 DI 로 주입받아 사용한다.
 */
final readonly class Config
{
    public function __construct(
        public string $appEnv,
        public bool $appDebug,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPass,
        public string $redisHost,
        public int $redisPort,
        public string $jwtSecret,
        public JwtAlgorithm $jwtAlgo,
        public int $jwtAccessTtl,
        public int $jwtRefreshTtl,
        public int $emailVerifyTtl,
        public string $appBaseUrl,
        public int $rateLimitAuth = 10,
        public int $rateLimitApi = 120,
        // AI 로그 분류(Claude API) — 키가 비면 분류를 건너뛰고 로그는 정상 저장된다.
        public string $anthropicApiKey = '',
        public string $anthropicModel = 'claude-haiku-4-5-20251001',
        public int $aiTimeout = 5,
        // RS256 전용 — HS256 에서는 빈 문자열. 검증은 Config::fromEnv() 에서 알고리즘별로 수행한다.
        public string $jwtPrivateKeyPath = '',
        public string $jwtPublicKeyPath = '',
        public string $jwtPrivateKeyPassphrase = '',
    ) {
    }

    /**
     * `$_ENV` 에서 설정을 읽어 생성한다. 필수 시크릿이 없으면 즉시 실패한다.
     */
    public static function fromEnv(): self
    {
        $jwtAlgo = self::enumAlgo('JWT_ALGO', JwtAlgorithm::HS256);
        $jwtSecret = self::str('JWT_SECRET', '');
        $jwtPrivateKeyPath = self::str('JWT_PRIVATE_KEY_PATH', '');
        $jwtPublicKeyPath = self::str('JWT_PUBLIC_KEY_PATH', '');

        // 알고리즘별로 필요한 시크릿이 다르므로 조건부 fail-fast 로 부팅 단계에서 즉시 검증한다.
        if ($jwtAlgo->isAsymmetric()) {
            if ($jwtPrivateKeyPath === '' || $jwtPublicKeyPath === '') {
                throw new RuntimeException(
                    'JWT_ALGO=RS256 에는 JWT_PRIVATE_KEY_PATH·JWT_PUBLIC_KEY_PATH 가 모두 필요합니다.',
                );
            }
            // 경로 문자열만이 아니라 실제 파일 읽기 가능 여부까지 확인해, 배포 시 경로 오타·
            // 권한 오류·키 배치 누락을 첫 요청이 아닌 부팅 시점에 잡는다.
            foreach (['개인키' => $jwtPrivateKeyPath, '공개키' => $jwtPublicKeyPath] as $label => $path) {
                if (!is_readable($path)) {
                    throw new RuntimeException(sprintf('JWT %s 파일을 읽을 수 없습니다: %s', $label, $path));
                }
            }
        } elseif ($jwtSecret === '') {
            throw new RuntimeException('JWT_SECRET 환경변수가 설정되지 않았습니다.');
        }

        return new self(
            appEnv: self::str('APP_ENV', 'production'),
            appDebug: self::bool('APP_DEBUG', false),
            dbHost: self::str('DB_HOST', '127.0.0.1'),
            dbPort: self::int('DB_PORT', 3306),
            dbName: self::str('DB_NAME', 'aitessera'),
            dbUser: self::str('DB_USER', 'root'),
            dbPass: self::str('DB_PASS', ''),
            redisHost: self::str('REDIS_HOST', '127.0.0.1'),
            redisPort: self::int('REDIS_PORT', 6379),
            jwtSecret: $jwtSecret,
            jwtAlgo: $jwtAlgo,
            jwtPrivateKeyPath: $jwtPrivateKeyPath,
            jwtPublicKeyPath: $jwtPublicKeyPath,
            jwtPrivateKeyPassphrase: self::str('JWT_PRIVATE_KEY_PASSPHRASE', ''),
            jwtAccessTtl: self::int('JWT_ACCESS_TTL', 900),
            jwtRefreshTtl: self::int('JWT_REFRESH_TTL', 1209600),
            emailVerifyTtl: self::int('EMAIL_VERIFY_TTL', 86400),
            appBaseUrl: self::str('APP_BASE_URL', 'http://localhost:9300/'),
            rateLimitAuth: self::int('RATE_LIMIT_AUTH', 10),
            rateLimitApi: self::int('RATE_LIMIT_API', 120),
            anthropicApiKey: self::str('ANTHROPIC_API_KEY', ''),
            anthropicModel: self::str('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
            aiTimeout: self::int('AI_TIMEOUT', 5),
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    private static function str(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function int(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? null;
        if (!is_string($value)) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    private static function enumAlgo(string $key, JwtAlgorithm $default): JwtAlgorithm
    {
        $value = $_ENV[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return $default;
        }

        $algo = JwtAlgorithm::tryFrom($value);
        if ($algo === null) {
            throw new RuntimeException(sprintf('지원하지 않는 JWT_ALGO 값입니다: %s (허용: HS256, RS256)', $value));
        }

        return $algo;
    }
}
