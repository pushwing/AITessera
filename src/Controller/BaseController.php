<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;

/**
 * 컨트롤러 공통 베이스 — 표준 JSON 응답 헬퍼를 제공한다.
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
}
