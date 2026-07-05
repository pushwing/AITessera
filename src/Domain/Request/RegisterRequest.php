<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Affiliation;
use App\Domain\PasswordPolicy;
use App\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * 회원가입 요청 DTO — respect/validation 으로 검증 후 생성한다.
 *
 * `profile` 은 소속별 부가 항목을 담는 자유 형식(free-form) 배열이며, 이번 범위에서는
 * 구조 검증 없이 그대로 저장한다(소속별 스키마 검증은 이슈 #8 에서 다룬다).
 */
final readonly class RegisterRequest
{
    /**
     * @param array<array-key, mixed> $profile
     */
    public function __construct(
        public string $email,
        public string $password,
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

        $errors = [];
        try {
            // 비밀번호는 정책 위반 메시지를 사람이 읽기 좋게 별도 처리하므로 여기서는 제외한다.
            v::key('email', v::stringType()->email())
                ->key('affiliation', v::in($affiliations))
                ->key('name', v::stringType()->notEmpty())
                ->key('contact', v::stringType()->notEmpty())
                ->key('company', v::optional(v::stringType()), false)
                ->key('terms_agreed', v::trueVal())
                ->key('third_party_agreed', v::trueVal())
                ->key('profile', v::optional(v::arrayType()), false)
                ->assert($data);
        } catch (NestedValidationException $e) {
            $errors = array_values($e->getMessages());
        }

        $password = $data['password'] ?? null;
        if (!is_string($password) || !PasswordPolicy::validator()->validate($password)) {
            $errors[] = PasswordPolicy::describe();
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $company = $data['company'] ?? null;
        $profile = $data['profile'] ?? [];

        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
            affiliation: Affiliation::from((string) $data['affiliation']),
            name: (string) $data['name'],
            contact: (string) $data['contact'],
            company: is_string($company) && $company !== '' ? $company : null,
            profile: is_array($profile) ? $profile : [],
        );
    }
}
