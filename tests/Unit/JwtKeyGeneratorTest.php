<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\JwtKeyGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtKeyGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aitessera_jwt_keygen_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (['jwt_private.pem', 'jwt_public.pem'] as $file) {
            $path = $this->dir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testGeneratesValidRsaKeyPair(): void
    {
        $privatePath = $this->dir . '/jwt_private.pem';
        $publicPath = $this->dir . '/jwt_public.pem';

        $result = (new JwtKeyGenerator())->generate($privatePath, $publicPath, bits: 2048);

        self::assertSame($privatePath, $result['privateKeyPath']);
        self::assertSame($publicPath, $result['publicKeyPath']);
        self::assertFileExists($privatePath);
        self::assertFileExists($publicPath);

        // openssl 이 실제 파싱 가능한 유효한 PEM 인지 확인한다.
        $privatePem = file_get_contents($privatePath);
        $publicPem = file_get_contents($publicPath);
        self::assertIsString($privatePem);
        self::assertIsString($publicPem);
        self::assertStringContainsString('PRIVATE KEY', $privatePem);
        self::assertStringContainsString('PUBLIC KEY', $publicPem);
        self::assertNotFalse(openssl_pkey_get_private($privatePem), '개인키가 유효한 PEM 이 아닙니다.');
        self::assertNotFalse(openssl_pkey_get_public($publicPem), '공개키가 유효한 PEM 이 아닙니다.');

        // 개인키는 소유자만 읽을 수 있어야 한다(생성 시점부터 0600).
        self::assertSame('0600', substr(sprintf('%o', fileperms($privatePath)), -4));
    }

    public function testRejectsExistingFileWithoutForce(): void
    {
        $privatePath = $this->dir . '/jwt_private.pem';
        $publicPath = $this->dir . '/jwt_public.pem';
        $generator = new JwtKeyGenerator();
        $generator->generate($privatePath, $publicPath, bits: 2048);

        $this->expectException(RuntimeException::class);
        $generator->generate($privatePath, $publicPath, bits: 2048);
    }

    public function testForceOverwritesExistingKeys(): void
    {
        $privatePath = $this->dir . '/jwt_private.pem';
        $publicPath = $this->dir . '/jwt_public.pem';
        $generator = new JwtKeyGenerator();
        $generator->generate($privatePath, $publicPath, bits: 2048);
        $first = file_get_contents($privatePath);

        $generator->generate($privatePath, $publicPath, bits: 2048, force: true);
        $second = file_get_contents($privatePath);

        self::assertNotSame($first, $second, 'force 재생성 시 새 키가 만들어져야 합니다.');
    }

    public function testRejectsWeakKeyLength(): void
    {
        $this->expectException(RuntimeException::class);
        (new JwtKeyGenerator())->generate(
            $this->dir . '/jwt_private.pem',
            $this->dir . '/jwt_public.pem',
            bits: 1024,
        );
    }
}
