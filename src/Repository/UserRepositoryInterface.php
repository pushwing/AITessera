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

    /**
     * 특정 소속의 회원 목록을 페이징 조회한다(운영자용, 이슈 #34).
     *
     * @param string      $sortColumn    정렬 컬럼(호출부에서 화이트리스트 보장)
     * @param string      $sortDirection 'ASC' 또는 'DESC'
     *
     * @return list<array<string, mixed>>
     */
    public function paginateByAffiliation(
        string $affiliation,
        ?int $role,
        ?bool $isActive,
        ?string $search,
        string $sortColumn,
        string $sortDirection,
        int $limit,
        int $offset,
    ): array;

    /**
     * paginateByAffiliation 와 동일 조건의 전체 건수(페이지네이션 meta 용).
     */
    public function countByAffiliation(
        string $affiliation,
        ?int $role,
        ?bool $isActive,
        ?string $search,
    ): int;

    /**
     * 소속 스코프 안에서 관리 대상 회원 상세를 조회한다. 타 소속·미존재는 null(→ 404).
     *
     * @return array<string, mixed>|null
     */
    public function findManageableById(int $id, string $affiliation): ?array;

    /**
     * 회원의 지정 컬럼들을 수정한다(운영자용 부분 수정, 이슈 #34).
     *
     * @param array<string, mixed> $fields 컬럼명 => 값(호출부에서 화이트리스트 보장)
     */
    public function updateFields(int $id, array $fields, DateTimeImmutable $at): void;

    /**
     * 회원 비밀번호 해시를 교체한다(운영자에 의한 재설정 또는 본인 변경).
     */
    public function updatePassword(int $id, string $passwordHash, DateTimeImmutable $at): void;

    /**
     * 회원을 소프트 삭제(탈퇴)한다 — deleted_at 설정 + 비활성화(이슈 #39).
     */
    public function softDelete(int $id, DateTimeImmutable $at): void;
}
