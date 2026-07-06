<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Affiliation;
use App\Domain\PasswordPolicy;
use App\Domain\ProfileSchema;
use App\Domain\UserRole;
use App\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * 운영자에 의한 계정 생성 요청 DTO — 운영자 전용 엔드포인트에서 사용한다(이슈 #29).
 *
 * `role` 은 운영자(1)·대행사(2)만 허용한다. 일반회원(3)은 공개 자가가입 전용이므로 이 경로로는
 * 만들 수 없다(혼동·우회 방지). 약관 동의 필드는 받지 않으며, 생성 시 서비스가 동의 시각을 채운다.
 */
final readonly class CreateOperatorRequest
{
    /**
     * @param array<array-key, mixed> $profile
     */
    public function __construct(
        public string $email,
        public string $password,
        public UserRole $role,
        public Affiliation $affiliation,
        public string $name,
        public string $contact,
        public ?string $company,
        public array $profile,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $affiliations = array_map(static fn (Affiliation $a): string => $a->value, Affiliation::cases());
        // 이 엔드포인트로 만들 수 있는 구분: 운영자(1)·대행사(2). 일반회원(3)은 금지.
        $creatableRoles = [UserRole::Operator->value, UserRole::Agency->value];

        $errors = [];
        try {
            // 비밀번호는 정책 위반 메시지를 사람이 읽기 좋게 별도 처리하므로 여기서는 제외한다.
            v::key('email', v::stringType()->email())
                ->key('role', v::intVal()->in($creatableRoles))
                ->key('affiliation', v::in($affiliations))
                ->key('name', v::stringType()->notEmpty())
                ->key('contact', v::stringType()->notEmpty())
                ->key('company', v::optional(v::stringType()), false)
                ->assert($data);
        } catch (NestedValidationException $e) {
            $errors = array_values($e->getMessages());
        }

        $password = $data['password'] ?? null;
        if (!is_string($password) || !PasswordPolicy::validator()->validate($password)) {
            $errors[] = PasswordPolicy::describe();
        }

        // profile 은 소속이 유효할 때만 해당 소속 스키마로 검증한다.
        $profile = $data['profile'] ?? [];
        $affiliationValue = $data['affiliation'] ?? null;
        if (is_string($affiliationValue) && in_array($affiliationValue, $affiliations, true)) {
            if (is_array($profile)) {
                $errors = array_merge($errors, ProfileSchema::validate(Affiliation::from($affiliationValue), $profile));
            } else {
                $errors[] = 'profile 은(는) 객체 형식이어야 합니다.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $company = $data['company'] ?? null;

        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
            role: UserRole::from((int) $data['role']),
            affiliation: Affiliation::from((string) $data['affiliation']),
            name: (string) $data['name'],
            contact: (string) $data['contact'],
            company: is_string($company) && $company !== '' ? $company : null,
            profile: is_array($profile) ? $profile : [],
        );
    }
}
