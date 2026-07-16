<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\UserRole;
use App\Support\ConnectionInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO 기반 사용자 저장소 — 모든 쿼리는 prepared statement + 바인딩.
 */
final readonly class UserRepository implements UserRepositoryInterface
{
    private const string COLUMNS = 'id, email, password_hash, affiliation, role, is_active, email_verified_at';
    private const string PROFILE_COLUMNS = 'id, email, name, affiliation, role, contact, company, profile, email_verified_at, created_at';
    private const string LIST_COLUMNS = 'id, email, name, affiliation, role, is_active, email_verified_at, created_at';
    private const string MANAGE_COLUMNS = 'id, email, name, affiliation, role, is_active, contact, company, profile, email_verified_at, last_login_at, created_at';

    /**
     * updateFields 로 수정 가능한 컬럼 화이트리스트 — 임의 컬럼 주입 방지(방어적 이중 방벽).
     *
     * @var list<string>
     */
    private const array UPDATABLE_COLUMNS = ['name', 'contact', 'company', 'profile', 'role', 'is_active'];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function findActiveByEmail(string $email, string $affiliation): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users
             WHERE email = :email AND affiliation = :affiliation AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['email' => $email, 'affiliation' => $affiliation]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findProfileById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::PROFILE_COLUMNS . ' FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        // 탈퇴(소프트 삭제)한 회원의 이메일은 점유로 보지 않는다 → 동일 이메일 재가입 허용(이슈 #39).
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1',
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(
        string $email,
        string $passwordHash,
        string $affiliation,
        string $name,
        string $contact,
        ?string $company,
        array $profile,
        DateTimeImmutable $agreedAt,
        UserRole $role = UserRole::Member,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, affiliation, role, name, contact, company, profile,
                 terms_agreed_at, third_party_agreed_at, created_at)
             VALUES
                (:email, :password_hash, :affiliation, :role, :name, :contact, :company, :profile,
                 :terms_agreed_at, :third_party_agreed_at, :created_at)',
        );
        $timestamp = $agreedAt->format('Y-m-d H:i:s');
        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'affiliation' => $affiliation,
            'role' => $role->value,
            'name' => $name,
            'contact' => $contact,
            'company' => $company,
            'profile' => $profile === [] ? null : json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'terms_agreed_at' => $timestamp,
            'third_party_agreed_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function markEmailVerified(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET email_verified_at = :at, updated_at = :updated_at WHERE id = :id',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    public function updateLastLogin(int $id, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET last_login_at = :at, updated_at = :updated_at WHERE id = :id',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    public function paginateByAffiliation(
        string $affiliation,
        ?int $role,
        ?bool $isActive,
        ?string $search,
        string $sortColumn,
        string $sortDirection,
        int $limit,
        int $offset,
    ): array {
        [$where, $params] = $this->buildListWhere($affiliation, $role, $isActive, $search);

        // $sortColumn·$sortDirection 은 호출부(UserListQuery)가 화이트리스트로 보장한 값만 들어온다.
        $sql = 'SELECT ' . self::LIST_COLUMNS . ' FROM users
             WHERE ' . $where . '
             ORDER BY ' . $sortColumn . ' ' . $sortDirection . '
             LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function countByAffiliation(
        string $affiliation,
        ?int $role,
        ?bool $isActive,
        ?string $search,
    ): int {
        [$where, $params] = $this->buildListWhere($affiliation, $role, $isActive, $search);

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM users WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findManageableById(int $id, string $affiliation): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::MANAGE_COLUMNS . ' FROM users
             WHERE id = :id AND affiliation = :affiliation AND deleted_at IS NULL
             LIMIT 1',
        );
        $stmt->execute(['id' => $id, 'affiliation' => $affiliation]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function updateFields(int $id, array $fields, DateTimeImmutable $at): void
    {
        // 화이트리스트 밖 컬럼은 무시한다(임의 컬럼 주입 방지).
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::UPDATABLE_COLUMNS, true)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = $at->format('Y-m-d H:i:s');
        $params['id'] = $id;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id',
        );
        $stmt->execute($params);
    }

    public function updatePassword(int $id, string $passwordHash, DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'password_hash' => $passwordHash,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    public function softDelete(int $id, DateTimeImmutable $at): void
    {
        // 소프트 삭제 + 비활성화(이슈 #39). 이후 모든 조회의 deleted_at IS NULL 필터에서 제외된다.
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET deleted_at = :at, is_active = 0, updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NULL',
        );
        $timestamp = $at->format('Y-m-d H:i:s');
        $stmt->execute([
            'at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $id,
        ]);
    }

    /**
     * 목록·건수 공용 WHERE 절과 바인딩 파라미터를 조립한다.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildListWhere(
        string $affiliation,
        ?int $role,
        ?bool $isActive,
        ?string $search,
    ): array {
        $conditions = ['affiliation = :affiliation', 'deleted_at IS NULL'];
        $params = ['affiliation' => $affiliation];

        if ($role !== null) {
            $conditions[] = 'role = :role';
            $params['role'] = $role;
        }
        if ($isActive !== null) {
            $conditions[] = 'is_active = :is_active';
            $params['is_active'] = $isActive ? 1 : 0;
        }
        if ($search !== null && $search !== '') {
            $conditions[] = '(email LIKE :search OR name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        return [implode(' AND ', $conditions), $params];
    }
}
