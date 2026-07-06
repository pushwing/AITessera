<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;

/**
 * 클라이언트 로그 수집 요청 DTO.
 */
final readonly class LogRequest
{
    /**
     * PSR-3 기준 허용 로그 레벨.
     *
     * @var list<string>
     */
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    /**
     * @param array<array-key, mixed> $context
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
        public ?string $source,
        public ?int $userId,
        public ?string $loggedAt,
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
            v::key('level', v::in(self::LEVELS))
                ->key('message', v::stringType()->notEmpty())
                ->key('context', v::optional(v::arrayType()), false)
                ->key('source', v::optional(v::stringType()), false)
                ->key('user_id', v::optional(v::intType()->positive()), false)
                ->key('logged_at', v::optional(v::dateTime()), false)
                ->assert($data);
        } catch (NestedValidationException $e) {
            throw new ValidationException(array_values($e->getMessages()));
        }

        $context = $data['context'] ?? [];
        $source = $data['source'] ?? null;
        $userId = $data['user_id'] ?? null;
        $loggedAt = $data['logged_at'] ?? null;

        return new self(
            level: (string) $data['level'],
            message: (string) $data['message'],
            context: is_array($context) ? $context : [],
            source: is_string($source) && $source !== '' ? $source : null,
            userId: is_int($userId) ? $userId : null,
            loggedAt: is_string($loggedAt) && $loggedAt !== '' ? $loggedAt : null,
        );
    }

    /**
     * 큐 적재용 페이로드.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'source' => $this->source,
            'user_id' => $this->userId,
            'logged_at' => $this->loggedAt,
        ];
    }
}
