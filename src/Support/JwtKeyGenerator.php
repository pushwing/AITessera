<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * RS256 서명용 RSA 키페어 생성기.
 *
 * openssl 확장으로 개인키·공개키(PEM)를 만들어 파일로 저장한다. 로컬 온보딩·CI 에서
 * `php bin/console jwt:keygen` 으로 호출한다. 운영 개인키는 이 도구가 아니라
 * SSM Parameter Store / Secrets Manager 로 안전하게 배치하는 것을 권장한다.
 */
final readonly class JwtKeyGenerator
{
    private const int MINIMUM_BITS = 2048;

    /**
     * 키페어를 생성해 두 경로에 PEM 으로 저장한다.
     *
     * @param non-empty-string $privateKeyPath 개인키(PEM) 저장 경로
     * @param non-empty-string $publicKeyPath  공개키(PEM) 저장 경로
     * @param bool             $force          기존 파일이 있어도 덮어쓸지 여부
     *
     * @return array{privateKeyPath: string, publicKeyPath: string, bits: int}
     *
     * @throws RuntimeException openssl 실패 · 잘못된 인자 · 기존 파일 보호 시
     */
    public function generate(
        string $privateKeyPath,
        string $publicKeyPath,
        int $bits = 4096,
        bool $force = false,
    ): array {
        if ($bits < self::MINIMUM_BITS) {
            throw new RuntimeException(sprintf('RSA 키 길이는 최소 %d 비트여야 합니다.', self::MINIMUM_BITS));
        }

        foreach ([$privateKeyPath, $publicKeyPath] as $path) {
            if (!$force && file_exists($path)) {
                throw new RuntimeException(sprintf('이미 파일이 존재합니다: %s (덮어쓰려면 force 옵션 사용)', $path));
            }
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('RSA 키 생성에 실패했습니다: ' . self::opensslErrors());
        }

        $privatePem = '';
        if (openssl_pkey_export($resource, $privatePem) === false) {
            throw new RuntimeException('개인키 PEM 추출에 실패했습니다: ' . self::opensslErrors());
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key']) || !is_string($details['key'])) {
            throw new RuntimeException('공개키 PEM 추출에 실패했습니다: ' . self::opensslErrors());
        }
        $publicPem = $details['key'];

        $this->writeKeyFile($privateKeyPath, $privatePem, 0600);
        $this->writeKeyFile($publicKeyPath, $publicPem, 0644);

        return [
            'privateKeyPath' => $privateKeyPath,
            'publicKeyPath' => $publicKeyPath,
            'bits' => $bits,
        ];
    }

    private function writeKeyFile(string $path, string $contents, int $permissions): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('디렉토리 생성에 실패했습니다: %s', $directory));
        }

        // umask 를 소유자 전용(0077)으로 좁힌 뒤 파일을 만들어, 생성 시점부터 개인키가
        // 넓은 기본 권한으로 잠깐 노출되는 TOCTOU 창을 없앤다. 쓰기 후 원래 umask 로 복원한다.
        $previousUmask = umask(0077);
        try {
            $written = file_put_contents($path, $contents);
        } finally {
            umask($previousUmask);
        }

        if ($written === false) {
            throw new RuntimeException(sprintf('키 파일 저장에 실패했습니다: %s', $path));
        }

        // 최종 목표 권한으로 명시 조정(공개키 0644 로 확장은 무해, 개인키는 이미 0600).
        chmod($path, $permissions);
    }

    private static function opensslErrors(): string
    {
        $messages = [];
        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? '알 수 없는 오류' : implode('; ', $messages);
    }
}
