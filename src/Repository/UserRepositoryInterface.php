<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * 사용자 데이터 접근 계약. 반환은 DB 행 배열(array<string, mixed>)로 통일한다.
 */
interface UserRepositoryInterface
{
    /**
     * 로그인 가능한(활성·미삭제) 사용자를 이메일로 조회한다.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByEmail(string $email): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    public function updateLastLogin(int $id, DateTimeImmutable $at): void;
}
