<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\UserRole;
use Attribute;

/**
 * 컨트롤러 액션에 필요한 회원구분(들)을 선언하는 메서드 어트리뷰트.
 *
 * `RoleGuardMiddleware` 가 라우트로 매칭된 핸들러 메서드에서 이 어트리뷰트를 리플렉션으로 읽어,
 * 요청의 회원구분과 대조한다. 라우트 정의(config/routes.php)와 권한 요구가 같은 곳(메서드 선언)에
 * 붙으므로, 별도 목록을 동기화할 필요가 없다.
 *
 * ```php
 * #[RequireRole(UserRole::Operator)]
 * public function create(ServerRequestInterface $request): ResponseInterface { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequireRole
{
    /** @var list<UserRole> */
    public array $roles;

    public function __construct(UserRole ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
