<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\JwtAlgorithm;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Config::fromEnv()` 의 알고리즘별 조건부 fail-fast 검증.
 */
final class ConfigTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $backup = [];

    private const array MANAGED_KEYS = [
        'JWT_ALGO',
        'JWT_SECRET',
        'JWT_PRIVATE_KEY_PATH',
        'JWT_PUBLIC_KEY_PATH',
        'JWT_PRIVATE_KEY_PASSPHRASE',
    ];

    private string $dir;

    protected function setUp(): void
    {
        foreach (self::MANAGED_KEYS as $key) {
            $value = $_ENV[$key] ?? null;
            $this->backup[$key] = is_string($value) ? $value : null;
            unset($_ENV[$key]);
        }

        // RS256 검증은 파일 읽기 가능 여부까지 확인하므로 실제 더미 키 파일을 만든다(내용은 무관).
        $this->dir = sys_get_temp_dir() . '/aitessera_config_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/private.pem', 'dummy');
        file_put_contents($this->dir . '/public.pem', 'dummy');
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        foreach (['private.pem', 'public.pem'] as $file) {
            $path = $this->dir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testHs256RequiresSecret(): void
    {
        $_ENV['JWT_ALGO'] = 'HS256';
        // JWT_SECRET 없음

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');
        Config::fromEnv();
    }

    public function testHs256IsDefaultAndYieldsEnum(): void
    {
        $_ENV['JWT_SECRET'] = 'a_test_secret_that_is_at_least_32_characters';

        $config = Config::fromEnv();

        self::assertSame(JwtAlgorithm::HS256, $config->jwtAlgo);
    }

    public function testRs256RequiresBothKeyPaths(): void
    {
        $_ENV['JWT_ALGO'] = 'RS256';
        $_ENV['JWT_PRIVATE_KEY_PATH'] = '/some/private.pem';
        // JWT_PUBLIC_KEY_PATH 없음

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RS256');
        Config::fromEnv();
    }

    public function testRs256WithKeyPathsDoesNotRequireSecret(): void
    {
        $privatePath = $this->dir . '/private.pem';
        $publicPath = $this->dir . '/public.pem';
        $_ENV['JWT_ALGO'] = 'RS256';
        $_ENV['JWT_PRIVATE_KEY_PATH'] = $privatePath;
        $_ENV['JWT_PUBLIC_KEY_PATH'] = $publicPath;
        // JWT_SECRET 없어도 예외가 아니어야 한다.

        $config = Config::fromEnv();

        self::assertSame(JwtAlgorithm::RS256, $config->jwtAlgo);
        self::assertSame($privatePath, $config->jwtPrivateKeyPath);
        self::assertSame($publicPath, $config->jwtPublicKeyPath);
    }

    public function testRs256WithUnreadableKeyFileThrows(): void
    {
        $_ENV['JWT_ALGO'] = 'RS256';
        $_ENV['JWT_PRIVATE_KEY_PATH'] = $this->dir . '/does_not_exist.pem';
        $_ENV['JWT_PUBLIC_KEY_PATH'] = $this->dir . '/public.pem';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('읽을 수 없습니다');
        Config::fromEnv();
    }

    public function testUnsupportedAlgorithmThrows(): void
    {
        $_ENV['JWT_ALGO'] = 'ES256';
        $_ENV['JWT_SECRET'] = 'a_test_secret_that_is_at_least_32_characters';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_ALGO');
        Config::fromEnv();
    }
}
