<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\UpdateMeRequest;
use App\Exception\ValidationException;
use App\Service\UserService;
use App\Support\JsonResponder;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 현재 인증된 사용자 정보 — 보호 엔드포인트 (JWT 필요).
 *
 * JwtAuthMiddleware 가 주입한 `userId` 애트리뷰트로 DB 에서 프로필을 조회해 반환한다.
 * (JWT 에는 최소 정보만 담으므로, "내 정보"는 이 엔드포인트가 DB 에서 채워 준다.)
 */
final class MeController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly UserService $userService,
    ) {
        parent::__construct($responder);
    }

    #[OA\Get(
        path: '/api/v1/me',
        summary: '현재 로그인 사용자 조회',
        description: '`Authorization: Bearer <access_token>` 로 인증한다. 토큰의 사용자 id 로 DB 프로필을 조회해 반환한다.',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OA\Response(response: 200, description: '사용자 프로필', content: new OA\JsonContent(ref: '#/components/schemas/MeResponse')),
            new OA\Response(response: 401, description: '인증 실패 (UNAUTHORIZED/INVALID_TOKEN/TOKEN_EXPIRED)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '사용자 없음 (NOT_FOUND)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) $request->getAttribute('userId');

        return $this->success($this->userService->me($userId)->toArray());
    }

    #[OA\Patch(
        path: '/api/v1/me',
        summary: '본인 정보 수정',
        description: '토큰의 사용자 본인 정보를 부분 수정한다. 이름·연락처·회사·프로필·비밀번호만 변경 가능하다(회원구분·활성상태·이메일 제외). 비밀번호 변경 시 `current_password` 를 함께 보내 본인 확인을 거친다.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateMeRequest')),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 200, description: '수정 후 사용자 프로필', content: new OA\JsonContent(ref: '#/components/schemas/MeResponse')),
            new OA\Response(response: 401, description: '인증 실패 또는 현재 비밀번호 불일치 (UNAUTHORIZED/INVALID_TOKEN/TOKEN_EXPIRED/INVALID_CREDENTIALS)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '사용자 없음 (NOT_FOUND)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '유효성 검사 실패 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) $request->getAttribute('userId');

        $dto = UpdateMeRequest::fromArray($this->jsonInput($request));

        return $this->success($this->userService->updateMe($dto, $userId)->toArray());
    }

    #[OA\Delete(
        path: '/api/v1/me',
        summary: '회원 탈퇴',
        description: '토큰의 사용자 본인 계정을 탈퇴(소프트 삭제)한다. 본인 확인을 위해 `password` 를 함께 보낸다. 탈퇴 시 모든 세션(Refresh 토큰)이 즉시 무효화된다.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DeleteMeRequest')),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 204, description: '탈퇴 완료(본문 없음)'),
            new OA\Response(response: 401, description: '인증 실패 또는 비밀번호 불일치 (UNAUTHORIZED/INVALID_TOKEN/TOKEN_EXPIRED/INVALID_CREDENTIALS)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: '사용자 없음 (NOT_FOUND)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '비밀번호 누락 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) $request->getAttribute('userId');

        // 본인 확인용 비밀번호는 필수 — 누락 시 인증 실패(401)가 아니라 유효성 오류(422)로 구분한다.
        $password = $this->jsonInput($request)['password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw new ValidationException(['password 는 필수입니다.']);
        }

        $this->userService->withdraw($userId, $password);

        return $this->noContent();
    }
}
