<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use App\Support\Rows;
use DateTimeImmutable;

/**
 * 운영자용 회원 조회 뷰 — 운영자가 소속 회원을 관리(목록·상세)할 때 노출하는 정보(이슈 #34).
 *
 * `password_hash` 등 민감 컬럼은 담지 않는다. 목록에는 경량 항목(`toListItem`)을, 상세에는
 * 전체 항목(`toArray`)을 반환한다. `fromRow` 는 두 컬럼셋(목록/상세)을 모두 허용하도록
 * 없는 키를 기본값으로 처리한다.
 */
final readonly class AdminUserView
{
    /**
     * @param array<array-key, mixed> $profile
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public Affiliation $affiliation,
        public UserRole $role,
        public bool $isActive,
        public ?string $contact,
        public ?string $company,
        public array $profile,
        public ?DateTimeImmutable $emailVerifiedAt,
        public ?DateTimeImmutable $lastLoginAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            affiliation: Affiliation::from((string) $row['affiliation']),
            role: UserRole::from((int) $row['role']),
            isActive: (bool) $row['is_active'],
            contact: Rows::nullableString($row['contact'] ?? null),
            company: Rows::nullableString($row['company'] ?? null),
            profile: Rows::decodeJsonObject($row['profile'] ?? null),
            emailVerifiedAt: Dates::nullable($row['email_verified_at'] ?? null),
            lastLoginAt: Dates::nullable($row['last_login_at'] ?? null),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * 목록 항목 응답(경량) — payload 최소화를 위해 프로필·연락처 등 상세 필드는 제외한다.
     *
     * @return array<string, mixed>
     */
    public function toListItem(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'affiliation' => $this->affiliation->value,
            'role' => $this->role->value,
            'is_active' => $this->isActive,
            'email_verified' => $this->emailVerifiedAt !== null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 상세 응답 — 관리에 필요한 전체 항목. 민감 정보는 포함하지 않는다.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'affiliation' => $this->affiliation->value,
            'role' => $this->role->value,
            'is_active' => $this->isActive,
            'contact' => $this->contact,
            'company' => $this->company,
            'profile' => $this->profile === [] ? null : $this->profile,
            'email_verified' => $this->emailVerifiedAt !== null,
            'last_login_at' => $this->lastLoginAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
