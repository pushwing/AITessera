<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * JWKS(JSON Web Key Set · RFC 7517) 공개키 표현 생성기.
 *
 * RS256(비대칭키) 공개키 PEM 을 외부 서비스가 자동 확인·검증할 수 있는 JWK 로 변환한다.
 * - 공개키에서 modulus(`n`)·exponent(`e`)를 추출해 base64url 로 인코딩한다.
 * - `kid`(Key ID)는 RFC 7638 thumbprint 로 **공개키에서 결정적으로 파생**하므로 별도 저장이
 *   필요 없다. 발급기(JwtIssuer)와 공개 엔드포인트가 각자 같은 값을 산출해 항상 일치한다.
 *
 * HS256(대칭키)에서는 공개할 키가 없다 — 시크릿은 절대 노출하지 않고 빈 키셋을 반환한다.
 */
final class Jwks
{
    /**
     * 계산된 JWK 캐시. 공개키는 불변이므로 인스턴스 수명 동안 1회만 계산한다.
     * - 미계산: false (sentinel — null 은 "HS256 이라 키 없음"이라는 유효 결과라서 구분한다)
     * - HS256: null
     * - RS256: JWK 배열
     *
     * @var array{kty: string, use: string, alg: string, kid: string, n: string, e: string}|null|false
     */
    private array|null|false $cachedJwk = false;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * JWKS 응답 본문. HS256 이면 빈 배열(`{"keys":[]}`)을 반환한다.
     *
     * @return array{keys: list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>}
     */
    public function keySet(): array
    {
        $jwk = $this->rsaPublicJwk();

        return ['keys' => $jwk === null ? [] : [$jwk]];
    }

    /**
     * 발급 토큰 헤더에 실을 `kid`. 공개키가 없는 HS256 에서는 null(헤더 미부착).
     */
    public function signingKid(): ?string
    {
        $jwk = $this->rsaPublicJwk();

        return $jwk === null ? null : $jwk['kid'];
    }

    /**
     * RS256 공개키를 JWK 로 변환한다. HS256 이면 null. 결과는 인스턴스에 캐싱한다.
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}|null
     */
    private function rsaPublicJwk(): ?array
    {
        if ($this->cachedJwk !== false) {
            return $this->cachedJwk;
        }

        if (!$this->config->jwtAlgo->isAsymmetric()) {
            return $this->cachedJwk = null;
        }

        ['n' => $n, 'e' => $e] = $this->rsaComponents();

        return $this->cachedJwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => $this->config->jwtAlgo->value,
            'kid' => self::thumbprint($n, $e),
            'n' => $n,
            'e' => $e,
        ];
    }

    /**
     * 공개키 PEM 에서 modulus·exponent 를 읽어 base64url 로 인코딩한다.
     *
     * @return array{n: string, e: string}
     *
     * @throws RuntimeException 공개키 파일을 읽을 수 없거나 RSA 키가 아닐 때
     */
    private function rsaComponents(): array
    {
        $pem = file_get_contents($this->config->jwtPublicKeyPath);
        if ($pem === false) {
            throw new RuntimeException(sprintf('공개키 파일을 읽을 수 없습니다: %s', $this->config->jwtPublicKeyPath));
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('공개키 PEM 파싱에 실패했습니다.');
        }

        $details = openssl_pkey_get_details($key);
        if (
            $details === false
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
            || !is_string($details['rsa']['n'])
            || !is_string($details['rsa']['e'])
        ) {
            throw new RuntimeException('RSA 공개키 구성요소(n·e)를 추출할 수 없습니다.');
        }

        return [
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    /**
     * RFC 7638 JWK Thumbprint — RSA 는 필수 멤버(`e`·`kty`·`n`)를 사전순·무공백으로
     * 직렬화한 뒤 SHA-256 해시를 base64url 로 인코딩한다.
     */
    private static function thumbprint(string $n, string $e): string
    {
        $canonical = sprintf('{"e":"%s","kty":"RSA","n":"%s"}', $e, $n);

        return self::base64Url(hash('sha256', $canonical, true));
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
