<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Version;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 헬스체크 — 서비스 라이브니스 확인용 공개 엔드포인트.
 *
 * 현재는 의존성 없는 라이브니스 응답만 반환한다. DB·Redis 연결 상태를 포함하는
 * 레디니스(readiness) 체크는 후속 작업에서 확장한다.
 */
final class HealthController extends BaseController
{
    #[OA\Get(
        path: '/health',
        summary: '헬스체크 — 서비스 라이브니스',
        security: [],
        tags: ['System'],
        responses: [new OA\Response(response: 200, description: '정상')],
    )]
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->success([
            'service' => Version::APP_NAME,
            'version' => Version::APP_VERSION,
            'status' => 'ok',
        ]);
    }
}
