<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 컨트롤러 공통 베이스 — 표준 JSON 응답·입력 헬퍼를 제공한다.
 *
 * 컨트롤러는 얇게(thin) 유지한다: 입력 검증 → Service 호출 → 응답 반환.
 */
abstract class BaseController
{
    public function __construct(protected readonly JsonResponder $responder)
    {
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function success(mixed $data = null, array $meta = [], int $statusCode = 200): ResponseInterface
    {
        return $this->responder->success($data, $meta, $statusCode);
    }

    protected function error(string $code, string $message, int $statusCode): ResponseInterface
    {
        return $this->responder->error($code, $message, $statusCode);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->responder->noContent();
    }

    /**
     * 요청 본문(폼 또는 JSON)을 연관 배열로 읽는다. 값 타입은 신뢰하지 않으며,
     * 검증·형변환은 요청 DTO(예: LoginRequest)에서 수행한다.
     *
     * @return array<array-key, mixed>
     */
    protected function jsonInput(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
