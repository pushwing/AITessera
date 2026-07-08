<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * JWT 서명 알고리즘.
 *
 * - HS256: 대칭키(HMAC). 단일 서버가 발급·검증을 모두 담당할 때. `JWT_SECRET` 하나로 서명·검증.
 * - RS256: 비대칭키(RSA). 발급자(개인키)와 검증자(공개키)를 분리한다. 서비스 간 검증 분산에 적합하며,
 *   개인키를 노출하지 않고 공개키만 배포해 검증을 위임할 수 있다.
 *
 * 어떤 값이든 검증 시 알고리즘을 명시적으로 고정하므로 `alg:none` 우회 공격이 차단된다.
 */
enum JwtAlgorithm: string
{
    case HS256 = 'HS256';
    case RS256 = 'RS256';

    /**
     * 비대칭키(공개/개인키) 방식인지 여부. RS256 은 개인키 파일 경로가 필요하다.
     */
    public function isAsymmetric(): bool
    {
        return $this === self::RS256;
    }
}
