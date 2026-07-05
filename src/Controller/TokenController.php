<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\LoginRequest;
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
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 201, description: '토큰 발급'),
            new OA\Response(response: 401, description: '자격증명 불일치'),
        ],
    )]
    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $dto = LoginRequest::fromArray($this->jsonInput($request));
        $pair = $this->authService->login($dto);

        return $this->tokenResponse($pair, 201);
    }

    #[OA\Post(
        path: '/api/v1/tokens/refresh',
        summary: 'Refresh 토큰으로 재발급 (회전)',
        security: [],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: '재발급'),
            new OA\Response(response: 401, description: '토큰 무효·만료'),
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
        tags: ['Auth'],
        responses: [new OA\Response(response: 204, description: '무효화 완료')],
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
