<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * 로그인 요청 컨텍스트 — 인증 계층에 전달되는 요청 메타데이터.
 *
 * 컨트롤러가 PSR-7 요청에서 IP·User-Agent 를 뽑아 조립한다(AuthService 는 요청 객체를
 * 모르므로 이 값 객체로 전달받는다). 비밀번호·토큰 등 민감정보는 절대 담지 않는다.
 */
final readonly class LoginContext
{
    public function __construct(
        public string $ip,
        public string $userAgent,
    ) {
    }
}
