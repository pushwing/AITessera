<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\CreateOperatorRequest;
use App\Domain\UserRole;
use App\Service\UserService;
use App\Support\JsonResponder;
use App\Support\RequireRole;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 운영자 전용 엔드포인트 — 운영자·대행사 계정 생성(이슈 #29).
 *
 * `#[RequireRole(UserRole::Operator)]` 로 보호되며, RoleGuardMiddleware 가 운영자 토큰이 아니면
 * 403(FORBIDDEN)으로 차단한다. 생성 계정은 운영자의 소속과 동일해야 하고 이메일 인증은 즉시 완료된다.
 */
final class OperatorController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly UserService $userService,
    ) {
        parent::__construct($responder);
    }

    #[RequireRole(UserRole::Operator)]
    #[OA\Post(
        path: '/api/v1/operators',
        summary: '운영자·대행사 계정 생성',
        description: '운영자 토큰으로만 호출할 수 있다. 자기 소속과 동일한 운영자(1)·대행사(2) 계정을 생성하며, 이메일 인증은 즉시 완료된다.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateOperatorRequest')),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 201, description: '계정 생성 완료', content: new OA\JsonContent(ref: '#/components/schemas/OperatorCreatedResponse')),
            new OA\Response(response: 401, description: '인증 실패 (UNAUTHORIZED/INVALID_TOKEN/TOKEN_EXPIRED)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: '운영자 아님 또는 타 소속 생성 시도 (FORBIDDEN)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: '이미 가입된 이메일 (ALREADY_EXISTS)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '유효성 검사 실패 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $dto = CreateOperatorRequest::fromArray($this->jsonInput($request));
        $creatorUserId = (int) $request->getAttribute('userId');
        $newId = $this->userService->createOperatorAccount($dto, $creatorUserId);

        return $this->success([
            'id' => $newId,
            'email' => $dto->email,
            'role' => $dto->role->value,
            'email_verified' => true,
        ], statusCode: 201);
    }
}
