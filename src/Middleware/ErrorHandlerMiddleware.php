<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\DomainException;
use App\Support\Config;
use App\Support\FileLogger;
use App\Support\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 파이프라인 최상단 전역 에러 핸들러.
 *
 * - 도메인 예외: 정의된 HTTP 상태코드·에러 코드로 표준 에러 응답 변환
 * - 그 외 예외: 500 INTERNAL_ERROR (운영 환경에서는 상세 메시지 숨김) + 로깅
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponder $responder,
        private Config $config,
        private FileLogger $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (DomainException $e) {
            return $this->responder->error($e->errorCode(), $e->getMessage(), $e->httpStatusCode());
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request->getUri()->getPath(),
            ]);

            $message = $this->config->appDebug
                ? $e->getMessage()
                : '서버 내부 오류가 발생했습니다.';

            return $this->responder->error('INTERNAL_ERROR', $message, 500);
        }
    }
}
