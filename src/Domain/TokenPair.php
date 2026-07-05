<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * 발급된 토큰 쌍 — Access(JWT, 단기) + Refresh(불투명 문자열, 장기).
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
    ) {
    }
}
