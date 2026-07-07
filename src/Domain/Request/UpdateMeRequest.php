<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\PasswordPolicy;
use App\Exception\ValidationException;

/**
 * 본인 정보 수정(PATCH /api/v1/me) 요청 DTO — 부분 수정을 지원한다(이슈 #39).
 *
 * 운영자용 UpdateUserRequest 와 달리 본인이 스스로 바꿀 수 있는 항목만 허용한다:
 * 이름·연락처·회사·프로필·비밀번호. 회원구분(role)·활성상태(is_active)·이메일·소속은 대상이 아니다.
 *
 * 비밀번호를 변경할 때는 탈취 토큰에 의한 무단 변경을 막기 위해 현재 비밀번호(current_password)를
 * 반드시 함께 받는다. 실제 일치 여부 검증은 해시를 아는 Service 에서 수행한다.
 * `profile` 은 소속 스키마 검증이 필요하므로 여기서는 구조(객체 여부)만 확인하고 Service 에서 검증한다.
 */
final readonly class UpdateMeRequest
{
    /**
     * @param list<string>            $provided 제공된 수정 필드명 목록
     * @param array<array-key, mixed> $profile
     */
    private function __construct(
        public array $provided,
        public ?string $name,
        public ?string $contact,
        public ?string $company,
        public array $profile,
        public ?string $password,
        public ?string $currentPassword,
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

        $password = null;
        $currentPassword = null;
        if (array_key_exists('password', $data)) {
            $provided[] = 'password';
            if (is_string($data['password']) && PasswordPolicy::validator()->validate($data['password'])) {
                $password = $data['password'];
            } else {
                $errors[] = PasswordPolicy::describe();
            }

            // 비밀번호 변경 시 현재 비밀번호 확인 필수(본인 확인).
            $current = $data['current_password'] ?? null;
            if (is_string($current) && $current !== '') {
                $currentPassword = $current;
            } else {
                $errors[] = 'current_password 는 비밀번호 변경 시 반드시 필요합니다.';
            }
        }

        if ($provided === []) {
            $errors[] = '수정할 필드가 없습니다.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($provided, $name, $contact, $company, $profile, $password, $currentPassword);
    }

    /**
     * 해당 필드가 요청 본문에 제공되었는지 여부.
     */
    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
