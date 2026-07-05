<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * 표준 JSON 응답 조립기.
 *
 * 성공/실패 응답 포맷을 프로젝트 전역에서 통일한다.
 * - 성공: { "status": "success", "data": ..., "meta": ... }
 * - 실패: { "status": "error", "code": "...", "message": "..." }
 */
final readonly class JsonResponder
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function success(mixed $data = null, array $meta = [], int $statusCode = 200): ResponseInterface
    {
        $payload = ['status' => 'success', 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->json($payload, $statusCode);
    }

    public function error(string $code, string $message, int $statusCode): ResponseInterface
    {
        return $this->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $statusCode): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));
    }
}
