<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AdminUserView;
use App\Domain\ProfileSchema;
use App\Domain\Request\UpdateUserRequest;
use App\Domain\Request\UserListQuery;
use App\Domain\User;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\UserRepositoryInterface;
use App\Support\ConnectionInterface;
use Psr\Clock\ClockInterface;

/**
 * 운영자용 회원 관리 유스케이스 — 목록·상세·수정(이슈 #34).
 *
 * 모든 작업은 요청 운영자 본인의 소속(affiliation) 안으로 스코핑된다. 타 소속 회원은
 * 조회·수정 대상에서 제외되어 존재 자체가 노출되지 않는다(NotFoundException). 운영자 권한
 * 여부는 상위 RoleGuardMiddleware 가 이미 보장하므로, 여기서는 활성 상태와 소속만 확인한다.
 */
final readonly class UserAdminService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private ConnectionInterface $db,
        private ClockInterface $clock,
    ) {
    }

    /**
     * 운영자 소속 회원 목록(페이징).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listUsers(UserListQuery $query, int $operatorUserId): array
    {
        $affiliation = $this->requireActiveOperator($operatorUserId)->affiliation->value;
        $role = $query->role?->value;

        $rows = $this->users->paginateByAffiliation(
            $affiliation,
            $role,
            $query->isActive,
            $query->search,
            $query->sortColumn,
            $query->sortDirection,
            $query->perPage,
            $query->offset(),
        );
        $total = $this->users->countByAffiliation($affiliation, $role, $query->isActive, $query->search);

        $items = array_map(
            static fn (array $row): array => AdminUserView::fromRow($row)->toListItem(),
            $rows,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 운영자 소속 회원 상세(회원구분 포함).
     */
    public function getUser(int $targetId, int $operatorUserId): AdminUserView
    {
        $affiliation = $this->requireActiveOperator($operatorUserId)->affiliation->value;

        $row = $this->users->findManageableById($targetId, $affiliation);
        if ($row === null) {
            throw new NotFoundException('회원을 찾을 수 없습니다.');
        }

        return AdminUserView::fromRow($row);
    }

    /**
     * 운영자 소속 회원 수정(부분 수정). 프로필·회원구분·활성상태·비밀번호를 변경한다.
     *
     * 자기 잠금 방지: 운영자는 본인 계정의 회원구분·활성상태를 바꿀 수 없다(스스로 운영자
     * 권한을 잃거나 계정을 비활성화하는 사고 차단).
     */
    public function updateUser(UpdateUserRequest $request, int $targetId, int $operatorUserId): void
    {
        $affiliation = $this->requireActiveOperator($operatorUserId)->affiliation->value;

        $row = $this->users->findManageableById($targetId, $affiliation);
        if ($row === null) {
            throw new NotFoundException('회원을 찾을 수 없습니다.');
        }
        $target = AdminUserView::fromRow($row);

        if ($targetId === $operatorUserId) {
            if ($request->has('role')) {
                throw new ForbiddenException('본인 계정의 회원구분은 변경할 수 없습니다.');
            }
            if ($request->has('is_active')) {
                throw new ForbiddenException('본인 계정의 활성 상태는 변경할 수 없습니다.');
            }
        }

        $fields = $this->buildUpdateFields($request, $target);

        $now = $this->clock->now();
        $password = $request->password;
        $this->db->transaction(function () use ($targetId, $fields, $password, $now): void {
            if ($fields !== []) {
                $this->users->updateFields($targetId, $fields, $now);
            }
            if ($password !== null) {
                $this->users->updatePassword($targetId, password_hash($password, PASSWORD_ARGON2ID), $now);
            }
        });
    }

    /**
     * 요청 본문에서 제공된 필드만 DB 컬럼 => 값 형태로 조립한다.
     *
     * @return array<string, mixed>
     */
    private function buildUpdateFields(UpdateUserRequest $request, AdminUserView $target): array
    {
        $fields = [];

        if ($request->name !== null) {
            $fields['name'] = $request->name;
        }
        if ($request->contact !== null) {
            $fields['contact'] = $request->contact;
        }
        if ($request->has('company')) {
            $fields['company'] = $request->company;
        }
        if ($request->has('profile')) {
            // 프로필은 대상 회원의 소속 스키마로 검증한다(소속은 수정 대상이 아니다).
            $errors = ProfileSchema::validate($target->affiliation, $request->profile);
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            $fields['profile'] = $request->profile === []
                ? null
                : json_encode($request->profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($request->role !== null) {
            $fields['role'] = $request->role->value;
        }
        if ($request->isActive !== null) {
            $fields['is_active'] = $request->isActive ? 1 : 0;
        }

        return $fields;
    }

    /**
     * 요청 운영자를 조회하고 활성 상태를 확인한다. 비활성이면 작업 불가.
     */
    private function requireActiveOperator(int $operatorUserId): User
    {
        $row = $this->users->findById($operatorUserId);
        if ($row === null) {
            throw new NotFoundException('요청자를 찾을 수 없습니다.');
        }

        $operator = User::fromRow($row);
        if (!$operator->isActive) {
            throw new ForbiddenException('비활성화된 계정은 이 작업을 수행할 수 없습니다.');
        }

        return $operator;
    }
}
