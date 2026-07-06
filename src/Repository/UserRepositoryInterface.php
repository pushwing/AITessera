<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\UserRole;
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

    /**
     * `/me` 노출용 안전 컬럼(민감정보 제외)만 조회한다.
     *
     * @return array<string, mixed>|null
     */
    public function findProfileById(int $id): ?array;

    public function emailExists(string $email): bool;

    /**
     * 신규 사용자를 생성하고 생성된 id 를 반환한다. 이메일 미인증 상태로 만든다.
     *
     * @param array<array-key, mixed> $profile 소속별 부가 항목(free-form) — JSON 으로 저장
     */
    public function create(
        string $email,
        string $passwordHash,
        string $affiliation,
        string $name,
        string $contact,
        ?string $company,
        array $profile,
        DateTimeImmutable $agreedAt,
        UserRole $role = UserRole::Member,
    ): int;

    public function markEmailVerified(int $id, DateTimeImmutable $at): void;

    public function updateLastLogin(int $id, DateTimeImmutable $at): void;
}
