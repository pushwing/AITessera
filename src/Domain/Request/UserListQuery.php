<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\UserRole;
use App\Exception\ValidationException;
use App\Support\Booleans;

/**
 * 운영자용 회원 목록 조회 쿼리 DTO — 쿼리스트링을 검증·정규화한다(이슈 #34).
 *
 * 소속(affiliation)은 요청 운영자 본인 것으로 강제되므로 필터로 받지 않는다. 정렬 필드는
 * 화이트리스트로만 허용해 SQL 인젝션을 원천 차단한다. 정렬 표기는 `-created_at`(내림차순),
 * `email`(오름차순) 형식을 따른다.
 */
final readonly class UserListQuery
{
    public const int DEFAULT_PER_PAGE = 20;
    public const int MAX_PER_PAGE = 100;

    /**
     * 허용 정렬 필드(요청값 => 실제 컬럼). 값은 신뢰 가능한 컬럼명으로만 구성한다.
     *
     * @var array<string, string>
     */
    private const array SORTABLE = [
        'created_at' => 'created_at',
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
    ];

    public function __construct(
        public int $page,
        public int $perPage,
        public ?UserRole $role,
        public ?bool $isActive,
        public ?string $search,
        public string $sortColumn,
        public string $sortDirection,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @throws ValidationException
     */
    public static function fromQueryParams(array $params): self
    {
        $errors = [];

        $page = self::positiveInt($params['page'] ?? null, 1);
        $perPage = self::positiveInt($params['per_page'] ?? null, self::DEFAULT_PER_PAGE);
        $perPage = min($perPage, self::MAX_PER_PAGE);

        $role = null;
        if (isset($params['role']) && $params['role'] !== '') {
            $roleRaw = $params['role'];
            $parsed = is_numeric($roleRaw) ? UserRole::tryFrom((int) $roleRaw) : null;
            if ($parsed === null) {
                $errors[] = 'role 필터 값이 올바르지 않습니다.';
            } else {
                $role = $parsed;
            }
        }

        $isActive = null;
        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $isActive = Booleans::parse($params['is_active']);
            if ($isActive === null) {
                $errors[] = 'is_active 필터 값이 올바르지 않습니다.';
            }
        }

        $search = null;
        if (isset($params['q']) && is_string($params['q']) && trim($params['q']) !== '') {
            $search = trim($params['q']);
        }

        $sortColumn = 'created_at';
        $sortDirection = 'DESC';
        if (isset($params['sort']) && is_string($params['sort']) && $params['sort'] !== '') {
            $raw = $params['sort'];
            $direction = 'ASC';
            if (str_starts_with($raw, '-')) {
                $direction = 'DESC';
                $raw = substr($raw, 1);
            }
            if (!array_key_exists($raw, self::SORTABLE)) {
                $errors[] = 'sort 필드가 올바르지 않습니다.';
            } else {
                $sortColumn = self::SORTABLE[$raw];
                $sortDirection = $direction;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($page, $perPage, $role, $isActive, $search, $sortColumn, $sortDirection);
    }

    /**
     * LIMIT/OFFSET 페이징의 OFFSET 값.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * 값이 양의 정수면 그 값을, 아니면 기본값을 돌려준다(1 미만은 기본값으로 보정).
     */
    private static function positiveInt(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;
            if ($int >= 1) {
                return $int;
            }
        }

        return $default;
    }
}
