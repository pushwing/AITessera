<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\LogRequest;
use App\Service\LogService;
use App\Support\JsonResponder;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 클라이언트 로그 수집 — 수신 즉시 큐에 적재하고 202 로 응답한다(DB 직접 쓰기 금지).
 */
final class LogController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly LogService $logService,
    ) {
        parent::__construct($responder);
    }

    #[OA\Post(
        path: '/api/v1/logs',
        summary: '클라이언트 로그 수집 (비동기)',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LogRequest')),
        tags: ['System'],
        responses: [
            new OA\Response(response: 202, description: '큐 적재 완료'),
            new OA\Response(response: 422, description: '유효성 검사 실패 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LogRequest::fromArray($this->jsonInput($request));
        $this->logService->record($dto);

        return $this->success(['accepted' => true], statusCode: 202);
    }
}
