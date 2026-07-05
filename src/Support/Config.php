<?php

declare(strict_types=1);

namespace App\Support;

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
        public string $jwtAlgo,
        public int $jwtAccessTtl,
        public int $jwtRefreshTtl,
        public int $emailVerifyTtl,
        public string $appBaseUrl,
    ) {
    }

    /**
     * `$_ENV` 에서 설정을 읽어 생성한다. 필수 시크릿이 없으면 즉시 실패한다.
     */
    public static function fromEnv(): self
    {
        $jwtSecret = self::str('JWT_SECRET', '');
        if ($jwtSecret === '') {
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
            jwtAlgo: self::str('JWT_ALGO', 'HS256'),
            jwtAccessTtl: self::int('JWT_ACCESS_TTL', 900),
            jwtRefreshTtl: self::int('JWT_REFRESH_TTL', 1209600),
            emailVerifyTtl: self::int('EMAIL_VERIFY_TTL', 86400),
            appBaseUrl: self::str('APP_BASE_URL', 'http://localhost:9300/'),
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
}
