<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\PasswordPolicy;
use App\Domain\UserRole;
use App\Exception\ValidationException;
use App\Support\Booleans;

/**
 * 운영자용 회원 수정(PATCH) 요청 DTO — 부분 수정을 지원한다(이슈 #34).
 *
 * 제공된(요청 본문에 키가 존재하는) 필드만 변경한다. `provided` 로 제공 여부를 추적해
 * "값 없음"과 "null 로 설정"(예: company 해제)을 구분한다. `profile` 은 대상 회원의 소속을
 * 알아야 스키마 검증이 가능하므로, 여기서는 구조(객체 여부)만 확인하고 소속별 검증은 Service 에서 한다.
 */
final readonly class UpdateUserRequest
{
    /**
     * @param list<string>            $provided 제공된 필드명 목록
     * @param array<array-key, mixed> $profile
     */
    private function __construct(
        public array $provided,
        public ?string $name,
        public ?string $contact,
        public ?string $company,
        public array $profile,
        public ?UserRole $role,
        public ?bool $isActive,
        public ?string $password,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $provided = [];

        $name = null;
        if (array_key_exists('name', $data)) {
            $provided[] = 'name';
            if (is_string($data['name']) && trim($data['name']) !== '') {
                $name = $data['name'];
            } else {
                $errors[] = 'name 은(는) 비어 있지 않은 문자열이어야 합니다.';
            }
        }

        $contact = null;
        if (array_key_exists('contact', $data)) {
            $provided[] = 'contact';
            if (is_string($data['contact']) && trim($data['contact']) !== '') {
                $contact = $data['contact'];
            } else {
                $errors[] = 'contact 은(는) 비어 있지 않은 문자열이어야 합니다.';
            }
        }

        $company = null;
        if (array_key_exists('company', $data)) {
            $provided[] = 'company';
            $raw = $data['company'];
            if ($raw === null || $raw === '') {
                $company = null; // 명시적 해제
            } elseif (is_string($raw)) {
                $company = $raw;
            } else {
                $errors[] = 'company 은(는) 문자열이거나 null 이어야 합니다.';
            }
        }

        $profile = [];
        if (array_key_exists('profile', $data)) {
            $provided[] = 'profile';
            if (is_array($data['profile'])) {
                $profile = $data['profile'];
            } else {
                $errors[] = 'profile 은(는) 객체 형식이어야 합니다.';
            }
        }

        $role = null;
        if (array_key_exists('role', $data)) {
            $provided[] = 'role';
            $raw = $data['role'];
            $parsed = is_numeric($raw) ? UserRole::tryFrom((int) $raw) : null;
            if ($parsed === null) {
                $errors[] = 'role 값이 올바르지 않습니다.';
            } else {
                $role = $parsed;
            }
        }

        $isActive = null;
        if (array_key_exists('is_active', $data)) {
            $provided[] = 'is_active';
            $isActive = Booleans::parse($data['is_active']);
            if ($isActive === null) {
                $errors[] = 'is_active 값이 올바르지 않습니다.';
            }
        }

        $password = null;
        if (array_key_exists('password', $data)) {
            $provided[] = 'password';
            if (is_string($data['password']) && PasswordPolicy::validator()->validate($data['password'])) {
                $password = $data['password'];
            } else {
                $errors[] = PasswordPolicy::describe();
            }
        }

        if ($provided === []) {
            $errors[] = '수정할 필드가 없습니다.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($provided, $name, $contact, $company, $profile, $role, $isActive, $password);
    }

    /**
     * 해당 필드가 요청 본문에 제공되었는지 여부.
     */
    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
