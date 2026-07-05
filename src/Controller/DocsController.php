<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\OpenApiGenerator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * API 문서 — OpenAPI 스펙(JSON)과 Swagger UI 페이지를 제공한다.
 *
 * 표준 응답 래퍼(JsonResponder)를 쓰지 않고 원시 JSON/HTML 을 반환하므로 BaseController 를
 * 상속하지 않는다.
 */
final readonly class DocsController
{
    private const string SPEC_PATH = '/api/v1/openapi.json';

    public function __construct(
        private OpenApiGenerator $generator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function spec(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($this->generator->toJson()));
    }

    public function ui(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream($this->html()));
    }

    private function html(): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="ko">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>AITessera API 문서</title>
          <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
        </head>
        <body>
          <div id="swagger-ui"></div>
          <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
          <script>
            window.onload = function () {
              window.ui = SwaggerUIBundle({
                url: '{$this->specPath()}',
                dom_id: '#swagger-ui',
              });
            };
          </script>
        </body>
        </html>
        HTML;
    }

    private function specPath(): string
    {
        return self::SPEC_PATH;
    }
}
