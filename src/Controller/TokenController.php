<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\LoginRequest;
use App\Domain\Security\LoginContext;
use App\Domain\TokenPair;
use App\Exception\ValidationException;
use App\Service\AuthService;
use App\Support\JsonResponder;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 토큰 엔드포인트 — 로그인(발급)·Refresh(회전)·로그아웃(무효화).
 *
 * 모두 공개 경로(`/api/v1/tokens*`)이며, 자체적으로 자격증명 또는 Refresh 토큰을 제시한다.
 */
final class TokenController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly AuthService $authService,
    ) {
        parent::__construct($responder);
    }

    #[OA\Post(
        path: '/api/v1/tokens',
        summary: '로그인 — 토큰 발급',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 201, description: '토큰 발급 — Access/Refresh 토큰 쌍', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')),
            new OA\Response(response: 401, description: '자격증명 불일치 (INVALID_CREDENTIALS)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: '이메일 미인증 (EMAIL_NOT_VERIFIED)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: '유효성 검사 실패 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LoginRequest::fromArray($this->jsonInput($request));
        $pair = $this->authService->login($dto, $this->loginContext($request));

        return $this->tokenResponse($pair, 201);
    }

    /**
     * 이상 탐지용 요청 컨텍스트(IP·User-Agent)를 조립한다.
     *
     * REMOTE_ADDR 만 신뢰한다 — 프록시(X-Forwarded-For) 신뢰는 별도 설정이 필요하므로
     * 여기서는 사용하지 않는다(RateLimitMiddleware 와 동일 정책).
     */
    private function loginContext(ServerRequestInterface $request): LoginContext
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return new LoginContext(
            is_string($ip) && $ip !== '' ? $ip : '0.0.0.0',
            $request->getHeaderLine('User-Agent'),
        );
    }

    #[OA\Post(
        path: '/api/v1/tokens/refresh',
        summary: 'Refresh 토큰으로 재발급 (회전)',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RefreshTokenRequest')),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: '재발급 — 새 Access/Refresh 토큰 쌍', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')),
            new OA\Response(response: 401, description: '토큰 무효·만료 (INVALID_TOKEN/TOKEN_EXPIRED)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $pair = $this->authService->refresh($this->requireRefreshToken($request));

        return $this->tokenResponse($pair, 200);
    }

    #[OA\Delete(
        path: '/api/v1/tokens',
        summary: '로그아웃 — Refresh 토큰 무효화',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RefreshTokenRequest')),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 204, description: '무효화 완료(본문 없음)'),
            new OA\Response(response: 422, description: 'refresh_token 누락 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->authService->logout($this->requireRefreshToken($request));

        return $this->noContent();
    }

    private function tokenResponse(TokenPair $pair, int $statusCode): ResponseInterface
    {
        return $this->success([
            'access_token' => $pair->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $pair->expiresIn,
            'refresh_token' => $pair->refreshToken,
        ], statusCode: $statusCode);
    }

    private function requireRefreshToken(ServerRequestInterface $request): string
    {
        $token = $this->jsonInput($request)['refresh_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ValidationException(['refresh_token 은 필수입니다.']);
        }

        return $token;
    }
}
