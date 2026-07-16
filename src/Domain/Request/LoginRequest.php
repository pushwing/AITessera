<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Affiliation;
use App\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * 로그인 요청 DTO — respect/validation 으로 검증 후 생성한다.
 *
 * `affiliation`은 동일 이메일이 소속별로 독립 계정을 가질 수 있어(동일 이메일 다중 소속 가입),
 * 어느 소속의 계정으로 로그인할지 명시하기 위해 필수로 받는다.
 */
final readonly class LoginRequest
{
    public function __construct(
        public string $email,
        public string $password,
        public Affiliation $affiliation,
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

        try {
            v::key('email', v::stringType()->email())
                ->key('password', v::stringType()->notEmpty())
                ->key('affiliation', v::in($affiliations))
                ->assert($data);
        } catch (NestedValidationException $e) {
            throw new ValidationException(array_values($e->getMessages()));
        }

        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
            affiliation: Affiliation::from((string) $data['affiliation']),
        );
    }
}
