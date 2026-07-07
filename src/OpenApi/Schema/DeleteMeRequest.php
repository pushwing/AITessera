<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 회원 탈퇴(DELETE /api/v1/me) 요청 본문(이슈 #39).
 *
 * 본인 확인을 위해 현재 비밀번호를 받는다.
 */
#[OA\Schema(
    schema: 'DeleteMeRequest',
    description: '회원 탈퇴 요청. 본인 확인용 비밀번호를 포함한다.',
    required: ['password'],
    properties: [
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '현재 비밀번호(본인 확인)', example: 'myPass1234!'),
    ],
)]
final class DeleteMeRequest
{
}
