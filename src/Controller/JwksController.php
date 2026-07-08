<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Jwks;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * JWKS(JSON Web Key Set) — RS256 공개키를 외부 서비스가 자동 확인·검증할 수 있게 노출한다.
 *
 * RFC 7517 표준 포맷(`{"keys":[...]}`)을 그대로 반환해야 하므로 프로젝트 표준 응답 래퍼
 * (`{status,data}`)를 쓰지 않는다 — DocsController 와 같은 이유로 BaseController 를 상속하지 않고
 * PSR-17 팩토리로 원시 JSON 을 조립한다.
 *
 * 공개키는 거의 바뀌지 않으므로 캐시 가능하게 응답한다(부하 분산). HS256 이면 빈 키셋을 반환한다.
 */
final readonly class JwksController
{
    public function __construct(
        private Jwks $jwks,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    #[OA\Get(
        path: '/.well-known/jwks.json',
        summary: 'JWKS — 공개키 자동 확인용 (RFC 7517 표준 경로)',
        security: [],
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON Web Key Set (RS256 이면 공개키 1개, HS256 이면 빈 배열)',
                content: new OA\JsonContent(ref: '#/components/schemas/JwksResponse'),
            ),
        ],
    )]
    public function wellKnown(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond();
    }

    #[OA\Get(
        path: '/api/v1/jwks.json',
        summary: 'JWKS — 공개키 자동 확인용 (버전 경로 별칭)',
        security: [],
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON Web Key Set (RS256 이면 공개키 1개, HS256 이면 빈 배열)',
                content: new OA\JsonContent(ref: '#/components/schemas/JwksResponse'),
            ),
        ],
    )]
    public function versioned(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond();
    }

    private function respond(): ResponseInterface
    {
        $body = json_encode($this->jwks->keySet(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/jwk-set+json; charset=utf-8')
            // 공개키는 거의 바뀌지 않으므로 1시간 공용 캐시 허용 — 검증 소비자의 재조회 부하를 줄인다.
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withBody($this->streamFactory->createStream($body));
    }
}
