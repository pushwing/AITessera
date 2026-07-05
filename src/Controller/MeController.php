<?php

declare(strict_types=1);

namespace App\Controller;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 현재 인증된 사용자 정보 — 보호 엔드포인트 (JWT 필요).
 *
 * JwtAuthMiddleware 가 주입한 `userId` 애트리뷰트를 읽어 반환한다.
 * 파이프라인의 인증 동작을 증명하는 데모 컨트롤러이며, 실제 사용자 조회는
 * UserService 도입 시 확장한다.
 */
final class MeController extends BaseController
{
    #[OA\Get(
        path: '/api/v1/me',
        summary: '현재 로그인 사용자 조회',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [new OA\Response(response: 200, description: '정상')],
    )]
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $userId = (int) $request->getAttribute('userId');

        return $this->success(['user_id' => $userId]);
    }
}
