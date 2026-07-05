<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Request\RegisterRequest;
use App\Exception\ValidationException;
use App\Service\UserService;
use App\Support\JsonResponder;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 사용자 엔드포인트 — 회원가입·이메일 인증.
 *
 * 회원가입은 토큰을 발급하지 않는다. 이메일 인증 완료 후 `/api/v1/tokens` 로 로그인한다.
 */
final class UserController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        private readonly UserService $userService,
    ) {
        parent::__construct($responder);
    }

    #[OA\Post(
        path: '/api/v1/users',
        summary: '회원가입',
        security: [],
        tags: ['Users'],
        responses: [
            new OA\Response(response: 201, description: '가입 완료(이메일 인증 대기)'),
            new OA\Response(response: 409, description: '이미 가입된 이메일'),
            new OA\Response(response: 422, description: '유효성 검사 실패'),
        ],
    )]
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $dto = RegisterRequest::fromArray($this->jsonInput($request));
        $userId = $this->userService->register($dto);

        return $this->success([
            'id' => $userId,
            'email' => $dto->email,
            'email_verified' => false,
        ], statusCode: 201);
    }

    #[OA\Post(
        path: '/api/v1/users/verify',
        summary: '이메일 인증',
        security: [],
        tags: ['Users'],
        responses: [
            new OA\Response(response: 200, description: '인증 완료'),
            new OA\Response(response: 401, description: '토큰 무효·만료'),
        ],
    )]
    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->requireStringField($request, 'token');
        $this->userService->verifyEmail($token);

        return $this->success(['verified' => true]);
    }

    #[OA\Post(
        path: '/api/v1/users/verify/resend',
        summary: '인증 메일 재발송',
        security: [],
        tags: ['Users'],
        responses: [new OA\Response(response: 202, description: '재발송 요청 접수')],
    )]
    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $email = $this->requireStringField($request, 'email');
        $this->userService->resendVerification($email);

        // 이메일 존재 여부를 노출하지 않도록 항상 202 로 응답한다.
        return $this->success(['message' => '인증 메일을 발송했습니다.'], statusCode: 202);
    }

    private function requireStringField(ServerRequestInterface $request, string $field): string
    {
        $value = $this->jsonInput($request)[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ValidationException([sprintf('%s 은(는) 필수입니다.', $field)]);
        }

        return $value;
    }
}
