<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — JWKS(JSON Web Key Set · RFC 7517) 응답.
 *
 * 외부 서비스가 RS256 공개키를 자동 확인·검증하는 데 사용한다. HS256 이면 `keys` 는 빈 배열.
 * 문서 전용 스키마 클래스(런타임 로직 없음). swagger-php 가 `src/` 스캔 시 수집한다.
 */
#[OA\Schema(
    schema: 'JwksResponse',
    description: 'JSON Web Key Set (RFC 7517) — 서명 검증용 공개키 집합',
    required: ['keys'],
    properties: [
        new OA\Property(
            property: 'keys',
            type: 'array',
            description: '공개키 목록 (RS256 이면 1개, HS256 이면 빈 배열)',
            items: new OA\Items(
                required: ['kty', 'use', 'alg', 'kid', 'n', 'e'],
                properties: [
                    new OA\Property(property: 'kty', type: 'string', description: '키 타입', enum: ['RSA'], example: 'RSA'),
                    new OA\Property(property: 'use', type: 'string', description: '용도(서명)', enum: ['sig'], example: 'sig'),
                    new OA\Property(property: 'alg', type: 'string', description: '서명 알고리즘', enum: ['RS256'], example: 'RS256'),
                    new OA\Property(property: 'kid', type: 'string', description: 'Key ID (RFC 7638 thumbprint)', example: 'Nz2v...redacted'),
                    new OA\Property(property: 'n', type: 'string', description: 'RSA modulus (base64url)', example: 'sXch...redacted'),
                    new OA\Property(property: 'e', type: 'string', description: 'RSA public exponent (base64url)', example: 'AQAB'),
                ],
            ),
        ),
    ],
)]
final class JwksResponse
{
}
