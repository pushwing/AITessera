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
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['level', 'message'],
                properties: [
                    new OA\Property(property: 'level', type: 'string', enum: LogRequest::LEVELS, example: 'error'),
                    new OA\Property(property: 'message', type: 'string', example: '결제 요청 실패'),
                    new OA\Property(property: 'context', type: 'object', additionalProperties: true, nullable: true),
                    new OA\Property(property: 'source', type: 'string', nullable: true, example: 'web-checkout'),
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true, example: 42),
                    new OA\Property(property: 'logged_at', type: 'string', format: 'date-time', nullable: true),
                ],
            ),
        ),
        tags: ['System'],
        responses: [
            new OA\Response(response: 202, description: '큐 적재 완료'),
            new OA\Response(response: 422, description: '유효성 검사 실패'),
        ],
    )]
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LogRequest::fromArray($this->jsonInput($request));
        $this->logService->record($dto);

        return $this->success(['accepted' => true], statusCode: 202);
    }
}
