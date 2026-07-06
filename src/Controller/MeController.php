<?php

declare(strict_types=1);

namespace App\Controller;

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
}
