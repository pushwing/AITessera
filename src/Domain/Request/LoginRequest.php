<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * 로그인 요청 DTO — respect/validation 으로 검증 후 생성한다.
 */
final readonly class LoginRequest
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        try {
            v::key('email', v::stringType()->email())
                ->key('password', v::stringType()->notEmpty())
                ->assert($data);
        } catch (NestedValidationException $e) {
            throw new ValidationException(array_values($e->getMessages()));
        }

        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
        );
    }
}
