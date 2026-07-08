<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\UserRole;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration as JwtConfiguration;
use Psr\Clock\ClockInterface;

/**
 * 토큰 발급기.
 *
 * - Access Token: JWT(lcobucci) · 단기 · `sub`(userId) + `aff`(소속) + `role`(회원구분) 클레임.
 *   서명 알고리즘(HS256/RS256)·키는 주입된 Configuration 이 결정하므로 이 클래스는 알고리즘에 비의존적이다.
 * - Refresh Token: 암호학적 난수 문자열(불투명) · DB 에는 SHA-256 해시만 저장
 */
final readonly class JwtIssuer
{
    public function __construct(
        private JwtConfiguration $jwtConfig,
        private Config $config,
        private ClockInterface $clock,
    ) {
    }

    public function issueAccessToken(int $userId, string $affiliation, UserRole $role): string
    {
        $now = $this->clock->now();

        return $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify(sprintf('+%d seconds', $this->config->jwtAccessTtl)))
            ->relatedTo((string) $userId)
            ->withClaim('aff', $affiliation)
            ->withClaim('role', $role->value)
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())
            ->toString();
    }

    /**
     * 새 Refresh 토큰(평문·해시·만료)을 생성한다. 평문은 클라이언트에만 반환하고
     * 저장은 해시로만 한다.
     *
     * @return array{token: string, hash: string, expiresAt: DateTimeImmutable}
     */
    public function generateRefreshToken(): array
    {
        $token = bin2hex(random_bytes(32));

        return [
            'token' => $token,
            'hash' => $this->hashRefreshToken($token),
            'expiresAt' => $this->clock->now()->modify(sprintf('+%d seconds', $this->config->jwtRefreshTtl)),
        ];
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function accessTtl(): int
    {
        return $this->config->jwtAccessTtl;
    }
}
