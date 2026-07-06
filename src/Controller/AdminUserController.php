<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\UpdateUserRequest;
use App\Domain\Request\UserListQuery;
use App\Domain\UserRole;
use App\Service\UserAdminService;
use App\Support\JsonResponder;
use App\Support\RequireRole;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 운영자 전용 회원 관리 엔드포인트 — 목록·상세·수정(이슈 #34).
 *
 * 모든 액션이 `#[RequireRole(UserRole::Operator)]` 로 보호되며, 운영자 본인 소속 회원만 다룬다.
 * 회원 생성은 별도 엔드포인트(POST /api/v1/operators)를 사용한다.
 */
final class AdminUserController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly UserAdminService $userAdminService,
    ) {
        parent::__construct($responder);
    }

    #[RequireRole(UserRole::Operator)]
    #[OA\Get(
        path: '/api/v1/users',
        summary: '회원 목록 조회(운영자용)',
        description: '운영자 토큰으로만 호출한다. 본인 소속 회원만 페이징 조회한다. 필터(role·is_active)·검색(q)·정렬(sort)을 지원한다.',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
            new OA\Parameter(name: 'role', in: 'query', required: false, description: '회원구분 필터 1=운영자 2=대행사 3=일반회원', schema: new OA\Schema(type: 'integer', enum: [1, 2, 3])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, description: '활성 여부 필터', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: '이메일·이름 부분 검색', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: '정렬(-접두어=내림차순). 허용: created_at·id·email·name', schema: new OA\Schema(type: 'string', default: '-created_at')),
        ],
        responses: [
            new OA\Response(response: 200, description: '회원 목록', content: new OA\JsonContent(ref: '#/components/schemas/UserListResponse')),
            new OA\Response(response: 401, description: '인증 실패', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: '운영자 아님 (FORBIDDEN)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '쿼리 파라미터 오류 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = UserListQuery::fromQueryParams($request->getQueryParams());
        $operatorId = (int) $request->getAttribute('userId');
        $result = $this->userAdminService->listUsers($query, $operatorId);

        return $this->success($result['items'], [
            'page' => $query->page,
            'per_page' => $query->perPage,
            'total' => $result['total'],
            'last_page' => (int) max(1, ceil($result['total'] / $query->perPage)),
        ]);
    }

    #[RequireRole(UserRole::Operator)]
    #[OA\Get(
        path: '/api/v1/users/{id}',
        summary: '회원 상세 조회(운영자용)',
        description: '운영자 토큰으로만 호출한다. 본인 소속 회원의 상세 정보(회원구분·활성상태 포함)를 반환한다.',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '회원 상세', content: new OA\JsonContent(ref: '#/components/schemas/UserDetailResponse')),
            new OA\Response(response: 401, description: '인증 실패', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: '운영자 아님 (FORBIDDEN)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '회원 없음 또는 타 소속 (NOT_FOUND)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $targetId = (int) $request->getAttribute('id');
        $operatorId = (int) $request->getAttribute('userId');

        return $this->success($this->userAdminService->getUser($targetId, $operatorId)->toArray());
    }

    #[RequireRole(UserRole::Operator)]
    #[OA\Patch(
        path: '/api/v1/users/{id}',
        summary: '회원 수정(운영자용)',
        description: '운영자 토큰으로만 호출한다. 본인 소속 회원의 프로필·회원구분·활성상태·비밀번호를 부분 수정한다. 본인 계정의 회원구분·활성상태는 변경할 수 없다(자기 잠금 방지).',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateUserRequest')),
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '수정 후 회원 상세', content: new OA\JsonContent(ref: '#/components/schemas/UserDetailResponse')),
            new OA\Response(response: 401, description: '인증 실패', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: '운영자 아님 또는 본인 잠금 시도 (FORBIDDEN)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '회원 없음 또는 타 소속 (NOT_FOUND)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '유효성 검사 실패 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $targetId = (int) $request->getAttribute('id');
        $operatorId = (int) $request->getAttribute('userId');

        $dto = UpdateUserRequest::fromArray($this->jsonInput($request));
        $this->userAdminService->updateUser($dto, $targetId, $operatorId);

        // 수정 후 최신 상태를 반환한다.
        return $this->success($this->userAdminService->getUser($targetId, $operatorId)->toArray());
    }
}
