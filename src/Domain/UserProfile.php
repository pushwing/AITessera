<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * 사용자 프로필 — `/api/v1/me` 등에서 노출하는 **안전한** 사용자 정보.
 *
 * `password_hash` 등 민감 컬럼은 담지 않는다. `profile`(소속별 부가 항목)은 JSON 을 디코드해
 * 배열로 보관한다.
 */
final readonly class UserProfile
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
        public string $contact,
        public ?string $company,
        public array $profile,
        public ?DateTimeImmutable $emailVerifiedAt,
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
            contact: (string) $row['contact'],
            company: self::nullableString($row['company'] ?? null),
            profile: self::decodeProfile($row['profile'] ?? null),
            emailVerifiedAt: Dates::nullable($row['email_verified_at'] ?? null),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * 응답용 배열(snake_case). 민감 정보는 포함하지 않는다.
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
            'contact' => $this->contact,
            'company' => $this->company,
            'profile' => $this->profile === [] ? null : $this->profile,
            'email_verified' => $this->emailVerifiedAt !== null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeProfile(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
