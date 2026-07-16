# 동일 이메일 다중 소속 가입 지원 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 같은 이메일로 서로 다른 제품군(affiliation)에 각각 독립된 계정으로 가입·로그인할 수 있게 한다.

**Architecture:** `users` 테이블의 이메일 유니크 제약을 `(email_active, affiliation)` 복합으로 확장하고, 로그인/가입/운영자생성/인증메일재발송 4개 유스케이스가 이메일 단독이 아니라 (이메일, 소속) 조합으로 사용자를 조회·중복체크하도록 리포지토리·서비스·DTO·컨트롤러를 일관되게 스코핑한다.

**Tech Stack:** PHP 8.4+, PDO(MySQL), Phinx, PSR-7/15, Respect\Validation, PHPUnit, PHPStan level 8.

## Global Constraints

- PSR-12 준수, `declare(strict_types=1)` 필수, 모든 메서드/프로퍼티 타입 선언 완전 적용
- SQL은 prepared statement/바인딩만 사용 — 문자열 직접 조합 금지
- 새 코드는 PHPStan level 8 통과 + 관련 PHPUnit 테스트 동반 (`composer check`)
- 이메일 존재 여부를 노출하지 않는 기존 보안 원칙 유지(`INVALID_CREDENTIALS`는 이메일 불일치·비밀번호 불일치·소속 불일치를 구분하지 않고 동일 메시지)
- 커밋 메시지: 이모지 + Conventional Commits 접두어 + 한국어 설명 (예: `✨ feat: ...`)
- 참고 스펙: [`docs/superpowers/specs/2026-07-16-multi-affiliation-accounts-design.md`](../specs/2026-07-16-multi-affiliation-accounts-design.md)

---

### Task 1: DB 마이그레이션 — 이메일 유니크 제약을 (email_active, affiliation) 복합으로 확장

**Files:**
- Create: `migrations/20260716120000_scope_users_email_unique_to_affiliation.php`

**Interfaces:**
- Produces: `users` 테이블의 유니크 인덱스 `uniq_users_email_active_affiliation (email_active, affiliation)` — 이후 모든 태스크가 전제하는 DB 제약

- [ ] **Step 1: 마이그레이션 파일 작성**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users 이메일 유니크 제약을 (email_active, affiliation) 복합으로 확장한다 — 이슈: 동일 이메일 다중 소속 가입.
 *
 * 기존 uniq_users_email_active(email_active)는 소속과 무관하게 이메일 전역 유일을 강제해,
 * 같은 사람이 서로 다른 제품군(affiliation)에 각각 가입하는 것을 막았다. affiliation을 인덱스에
 * 포함시켜 "이메일 유일성"의 스코프를 소속 단위로 좁힌다. email_active는 탈퇴 시 NULL이 되는
 * 기존 생성 컬럼을 그대로 재사용하므로 탈퇴 후 재가입 허용 동작은 변하지 않는다.
 *
 * 기존 데이터는 이미 (소속당 이메일 유일)을 만족하므로 무중단 적용 가능(백필 불필요).
 */
final class ScopeUsersEmailUniqueToAffiliation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email_active');
        $this->execute(
            'ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active_affiliation (email_active, affiliation)',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE users DROP INDEX uniq_users_email_active_affiliation');
        $this->execute('ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active (email_active)');
    }
}
```

- [ ] **Step 2: 마이그레이션 적용**

Run: `php bin/console migrate`
Expected: 출력에 `ScopeUsersEmailUniqueToAffiliation` 마이그레이션이 `migrated`로 표시되고 예외 없음.

- [ ] **Step 3: 인덱스가 실제로 바뀌었는지 확인**

Run:
```bash
php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s", $_ENV["DB_HOST"], $_ENV["DB_NAME"]), $_ENV["DB_USER"], $_ENV["DB_PASS"]);
foreach ($pdo->query("SHOW INDEX FROM users WHERE Key_name LIKE %uniq_users_email%") as $row) {
    echo $row["Key_name"], " ", $row["Column_name"], PHP_EOL;
}
'
```
Expected: `uniq_users_email_active_affiliation email_active`와 `uniq_users_email_active_affiliation affiliation` 두 줄만 출력(기존 `uniq_users_email_active` 단독 인덱스는 사라짐).

- [ ] **Step 4: rollback 동작 확인 후 다시 migrate**

Run: `php bin/console rollback` 다음 `php bin/console migrate`
Expected: 둘 다 예외 없이 성공(down()이 정상적으로 이전 상태로 되돌리고, 재적용도 성공).

- [ ] **Step 5: 커밋**

```bash
git add migrations/20260716120000_scope_users_email_unique_to_affiliation.php
git commit -m "$(cat <<'EOF'
✨ feat: users 이메일 유니크 제약을 소속(affiliation) 단위로 확장

동일 이메일로 서로 다른 제품군에 각각 가입할 수 있도록 (email_active, affiliation)
복합 유니크 인덱스로 변경.
EOF
)"
```

---

### Task 2: 로그인 — LoginRequest에 affiliation 필드 추가

**Files:**
- Modify: `src/Domain/Request/LoginRequest.php`
- Test: `tests/Unit/LoginRequestTest.php`

**Interfaces:**
- Consumes: `App\Domain\Affiliation` enum (`Affiliation::cases()`, `Affiliation::from()`)
- Produces: `LoginRequest` 생성자가 `(string $email, string $password, Affiliation $affiliation)` 3개 인자를 받음. `AuthService::login()`(Task 3)이 `$request->affiliation` 사용.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/Unit/LoginRequestTest.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\Request\LoginRequest;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class LoginRequestTest extends TestCase
{
    public function testValidDataProducesDto(): void
    {
        $request = LoginRequest::fromArray([
            'email' => 'user@aivance.test',
            'password' => 'secret',
            'affiliation' => 'aivance',
        ]);

        self::assertSame('user@aivance.test', $request->email);
        self::assertSame('secret', $request->password);
        self::assertSame(Affiliation::Aivance, $request->affiliation);
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'not-an-email', 'password' => 'secret', 'affiliation' => 'aivance']);
    }

    public function testMissingPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'affiliation' => 'aivance']);
    }

    public function testEmptyPasswordThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => '', 'affiliation' => 'aivance']);
    }

    public function testMissingAffiliationThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => 'secret']);
    }

    public function testInvalidAffiliationThrows(): void
    {
        $this->expectException(ValidationException::class);
        LoginRequest::fromArray(['email' => 'user@aivance.test', 'password' => 'secret', 'affiliation' => 'not-a-real-affiliation']);
    }
}
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `vendor/bin/phpunit tests/Unit/LoginRequestTest.php`
Expected: FAIL — `testValidDataProducesDto` 등에서 `Unknown named parameter $affiliation` 또는 `affiliation` 프로퍼티 미존재 에러.

- [ ] **Step 3: LoginRequest 구현 수정**

`src/Domain/Request/LoginRequest.php` 전체를 아래로 교체:

```php
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
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/Unit/LoginRequestTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: 커밋**

```bash
git add src/Domain/Request/LoginRequest.php tests/Unit/LoginRequestTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 로그인 요청에 affiliation 필수 파라미터 추가

동일 이메일이 소속별로 독립 계정을 가질 수 있어, 로그인 시 어느 소속 계정인지
명시하도록 LoginRequest에 affiliation을 추가.
EOF
)"
```

---

### Task 3: 로그인 — Repository·AuthService를 (email, affiliation) 스코프로 변경

**Files:**
- Modify: `src/Repository/UserRepositoryInterface.php:20` (`findActiveByEmail` 시그니처)
- Modify: `src/Repository/UserRepository.php:33-44` (`findActiveByEmail` 구현)
- Modify: `src/Service/AuthService.php:50` (`login()` 호출부)
- Test: `tests/Unit/AuthServiceTest.php`

**Interfaces:**
- Consumes: Task 2의 `LoginRequest::$affiliation` (`Affiliation` enum, `->value`로 string 변환)
- Produces: `UserRepositoryInterface::findActiveByEmail(string $email, string $affiliation): ?array` — Task 4·5는 건드리지 않음(별도 메서드)

- [ ] **Step 1: 실패하는 테스트로 갱신**

`tests/Unit/AuthServiceTest.php`에서 `new LoginRequest(...)` 호출 6곳 전부와 `findActiveByEmail` mock 기대치를 아래처럼 수정(파일 상단 `use` 및 각 테스트 메서드 diff):

```php
// use App\Domain\Request\LoginRequest; 아래에 추가
use App\Domain\Affiliation;
```

```php
    public function testLoginIssuesTokenPairAndUpdatesLastLogin(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->with('user@aivance.test', 'aivance')->willReturn($this->userRow());
        $users->expects(self::once())->method('updateLastLogin');

        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('store');

        $pair = $this->service($users, $tokens)
            ->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
        self::assertSame(900, $pair->expiresIn);
    }

    public function testLoginWithWrongPasswordThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', 'wrong', Affiliation::Aivance), $this->context());
    }

    public function testLoginWithUnknownEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn(null);
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('nobody@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }

    public function testLoginWithUnverifiedEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow(['email_verified_at' => null]));
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(EmailNotVerifiedException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }
```

```php
    public function testLoginPushesSuccessEventToQueue(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            AuthService::LOGIN_EVENT_QUEUE,
            self::callback(static function (array $payload): bool {
                // 민감정보(비밀번호·토큰)는 절대 담기지 않아야 한다
                return $payload['email'] === 'user@aivance.test'
                    && $payload['ip'] === '203.0.113.7'
                    && $payload['success'] === true
                    && !isset($payload['password'])
                    && !isset($payload['access_token']);
            }),
        );

        $this->service($users, $tokens, $queue)
            ->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Aivance), $this->context());
    }

    public function testLoginPushesFailureEventToQueue(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow());
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push')->with(
            AuthService::LOGIN_EVENT_QUEUE,
            self::callback(static fn (array $payload): bool => $payload['success'] === false),
        );

        try {
            $this->service($users, $tokens, $queue)
                ->login(new LoginRequest('user@aivance.test', 'wrong', Affiliation::Aivance), $this->context());
            self::fail('실패한 로그인은 예외를 던져야 한다');
        } catch (InvalidCredentialsException) {
            // 기대된 예외 — 이벤트 push 검증은 위 expects() 로 수행
        }
    }
```

새 테스트 케이스를 파일 끝(`testLoginWithUnverifiedEmailThrows` 뒤)에 추가:

```php
    public function testLoginWithMismatchedAffiliationThrows(): void
    {
        // 이메일은 aivance 소속엔 없고 ailicet 소속에만 있는 상황 — findActiveByEmail이 조회한
        // affiliation 인자에 대해 null을 반환하도록 스텁.
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->with('user@aivance.test', 'ailicet')->willReturn(null);
        $tokens = $this->createMock(RefreshTokenRepositoryInterface::class);

        $this->expectException(InvalidCredentialsException::class);
        $this->service($users, $tokens)->login(new LoginRequest('user@aivance.test', self::PASSWORD, Affiliation::Ailicet), $this->context());
    }
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `vendor/bin/phpunit tests/Unit/AuthServiceTest.php`
Expected: FAIL — `LoginRequest`에 3번째 인자가 필요하다는 타입 에러(현재 구현은 2개 인자만 받음).

- [ ] **Step 3: UserRepositoryInterface 시그니처 변경**

`src/Repository/UserRepositoryInterface.php:16-20`을 아래로 교체:

```php
    /**
     * 로그인 가능한(활성·미삭제) 사용자를 (이메일, 소속) 조합으로 조회한다.
     *
     * 동일 이메일이 소속별로 독립 계정을 가질 수 있으므로 affiliation까지 함께 매칭한다.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByEmail(string $email, string $affiliation): ?array;
```

- [ ] **Step 4: UserRepository 구현 변경**

`src/Repository/UserRepository.php:33-44`를 아래로 교체:

```php
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
```

- [ ] **Step 5: AuthService::login() 호출부 변경**

`src/Service/AuthService.php:50`을 아래로 교체:

```php
            $row = $this->users->findActiveByEmail($request->email, $request->affiliation->value);
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/Unit/AuthServiceTest.php tests/Unit/LoginRequestTest.php`
Expected: PASS (전체)

Run: `composer analyse`
Expected: PHPStan level 8 — 에러 없음 (특히 `UserRepositoryInterface`를 구현하는 다른 클래스가 있는지 `grep -rn "implements UserRepositoryInterface" src/`로 확인하고, 있다면 같이 수정)

- [ ] **Step 7: 커밋**

```bash
git add src/Repository/UserRepositoryInterface.php src/Repository/UserRepository.php src/Service/AuthService.php tests/Unit/AuthServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 로그인 조회를 (이메일, 소속) 스코프로 변경

findActiveByEmail이 affiliation까지 매칭하도록 해 동일 이메일의 다른 소속 계정과
혼동되지 않게 함.
EOF
)"
```

---

### Task 4: 회원가입·운영자생성 — emailExists를 (email, affiliation) 스코프로 변경

**Files:**
- Modify: `src/Repository/UserRepositoryInterface.php` (`emailExists` 시그니처)
- Modify: `src/Repository/UserRepository.php:72-81` (`emailExists` 구현)
- Modify: `src/Service/UserService.php:167`, `:226` (`register`, `createOperatorAccount` 호출부)
- Test: `tests/Unit/UserServiceTest.php`

**Interfaces:**
- Consumes: `RegisterRequest::$affiliation`, `CreateOperatorRequest::$affiliation` (이미 존재하는 `Affiliation` enum 필드)
- Produces: `UserRepositoryInterface::emailExists(string $email, string $affiliation): bool`

- [ ] **Step 1: 실패하는 테스트로 갱신**

`tests/Unit/UserServiceTest.php`의 아래 4개 테스트에 `->with()` 기대치 추가(기존 `willReturn`만 있던 부분을 `with(...)->willReturn(...)`로):

```php
    public function testRegisterCreatesUserAndEnqueuesVerification(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('emailExists')->with('user@aivance.test', 'aivance')->willReturn(false);
        $users->expects(self::once())->method('create')->willReturn(100);
        // ... 이하 기존 코드 동일
```

```php
    public function testRegisterWithDuplicateEmailThrows(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('emailExists')->with('user@aivance.test', 'aivance')->willReturn(true);
        $users->expects(self::never())->method('create');
        // ... 이하 기존 코드 동일
```

```php
    public function testCreateOperatorAccountCreatesRoleAndMarksVerified(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1]));
        $users->method('emailExists')->with('agency@aivance.test', 'aivance')->willReturn(false);
        // ... 이하 기존 코드 동일
```

```php
    public function testCreateOperatorAccountRejectsDuplicateEmail(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with(100)->willReturn($this->userRow(['role' => 1]));
        $users->method('emailExists')->with('agency@aivance.test', 'aivance')->willReturn(true);
        $users->expects(self::never())->method('create');
        // ... 이하 기존 코드 동일
```

새 테스트를 `testRegisterWithDuplicateEmailThrows` 뒤에 추가:

```php
    public function testRegisterWithSameEmailDifferentAffiliationSucceeds(): void
    {
        // aivance엔 이미 있지만 ailicet엔 없는 이메일 — ailicet 가입은 성공해야 한다.
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('emailExists')->with('user@aivance.test', 'ailicet')->willReturn(false);
        $users->expects(self::once())->method('create')->willReturn(101);

        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->expects(self::once())->method('store');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push');

        $request = new RegisterRequest(
            email: 'user@aivance.test',
            password: 'password1234!',
            affiliation: Affiliation::Ailicet,
            name: '홍길동',
            contact: '010-1234-5678',
            company: null,
            profile: [],
        );

        $userId = $this->service($users, $verifications, $queue)->register($request);

        self::assertSame(101, $userId);
    }
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `vendor/bin/phpunit tests/Unit/UserServiceTest.php`
Expected: FAIL — `emailExists`에 대한 `with()` 기대치가 인자 개수 불일치로 실패(현재 구현은 `emailExists(string $email)` 1개 인자만 받음).

- [ ] **Step 3: UserRepositoryInterface 시그니처 변경**

`src/Repository/UserRepositoryInterface.php`의 `emailExists` 선언을 아래로 교체:

```php
    /**
     * (이메일, 소속) 조합으로 활성 계정 존재 여부를 확인한다.
     *
     * 동일 이메일이 소속별로 독립 계정을 가질 수 있으므로 affiliation까지 함께 확인한다.
     */
    public function emailExists(string $email, string $affiliation): bool;
```

- [ ] **Step 4: UserRepository 구현 변경**

`src/Repository/UserRepository.php:72-81`을 아래로 교체:

```php
    public function emailExists(string $email, string $affiliation): bool
    {
        // 탈퇴(소프트 삭제)한 회원의 이메일은 점유로 보지 않는다 → 동일 이메일 재가입 허용(이슈 #39).
        // 소속이 다르면 별개 계정으로 취급한다 → 동일 이메일 다중 소속 가입 허용.
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM users WHERE email = :email AND affiliation = :affiliation AND deleted_at IS NULL LIMIT 1',
        );
        $stmt->execute(['email' => $email, 'affiliation' => $affiliation]);

        return $stmt->fetchColumn() !== false;
    }
```

- [ ] **Step 5: UserService 호출부 변경**

`src/Service/UserService.php:167`을 아래로 교체:

```php
        if ($this->users->emailExists($request->email, $request->affiliation->value)) {
```

`src/Service/UserService.php:226`을 동일하게 교체:

```php
        if ($this->users->emailExists($request->email, $request->affiliation->value)) {
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/Unit/UserServiceTest.php`
Expected: PASS (전체)

Run: `composer analyse`
Expected: 에러 없음

- [ ] **Step 7: 커밋**

```bash
git add src/Repository/UserRepositoryInterface.php src/Repository/UserRepository.php src/Service/UserService.php tests/Unit/UserServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 회원가입·운영자계정생성 중복체크를 (이메일, 소속) 스코프로 변경

emailExists가 affiliation까지 매칭해, 같은 이메일이 다른 소속에 새로 가입하는 것을
허용하고 같은 소속 내 중복만 막음.
EOF
)"
```

---

### Task 5: 인증메일 재발송 — affiliation 파라미터 추가

**Files:**
- Modify: `src/Service/UserService.php:282-304` (`resendVerification`)
- Modify: `src/Controller/UserController.php` (`resend` 메서드)
- Modify: `src/OpenApi/Schema/EmailResendRequest.php`
- Test: `tests/Unit/UserServiceTest.php`

**Interfaces:**
- Consumes: Task 3의 `UserRepositoryInterface::findActiveByEmail(string $email, string $affiliation)`
- Produces: `UserService::resendVerification(string $email, string $affiliation): void` — Task 6(E2E)이 이 시그니처로 호출

- [ ] **Step 1: 실패하는 테스트로 갱신**

`tests/Unit/UserServiceTest.php`의 3개 resend 테스트를 아래로 교체:

```php
    public function testResendReissuesForUnverifiedUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->with('user@aivance.test', 'aivance')->willReturn($this->userRow(['email_verified_at' => null]));

        $verifications = $this->createMock(EmailVerificationRepositoryInterface::class);
        $verifications->expects(self::once())->method('deleteUnconsumedForUser')->with(100);
        $verifications->expects(self::once())->method('store');

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('push');

        $this->service($users, $verifications, $queue)->resendVerification('user@aivance.test', 'aivance');
    }

    public function testResendIsNoopForVerifiedUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn($this->userRow(['email_verified_at' => '2026-07-01 00:00:00']));

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->resendVerification('user@aivance.test', 'aivance');
    }

    public function testResendIsNoopForUnknownEmail(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findActiveByEmail')->willReturn(null);

        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::never())->method('push');

        $this->service($users, $this->createMock(EmailVerificationRepositoryInterface::class), $queue)
            ->resendVerification('nobody@aivance.test', 'aivance');
    }
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `vendor/bin/phpunit tests/Unit/UserServiceTest.php`
Expected: FAIL — `resendVerification()`에 인자가 너무 많다는 타입 에러(현재 구현은 1개 인자만 받음).

- [ ] **Step 3: UserService::resendVerification 시그니처 변경**

`src/Service/UserService.php:282-284`를 아래로 교체:

```php
    public function resendVerification(string $email, string $affiliation): void
    {
        $row = $this->users->findActiveByEmail($email, $affiliation);
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/Unit/UserServiceTest.php`
Expected: PASS (전체)

- [ ] **Step 5: UserController::resend 갱신**

`src/Controller/UserController.php`의 `resend` 메서드를 아래로 교체(파일 상단 `use` 목록에 `use App\Domain\Affiliation;` 추가):

```php
    #[OA\Post(
        path: '/api/v1/users/verify/resend',
        summary: '인증 메일 재발송',
        description: '이메일 존재 여부를 노출하지 않도록, 대상이 없거나 이미 인증된 경우에도 202 로 응답한다.',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EmailResendRequest')),
        tags: ['Users'],
        responses: [
            new OA\Response(response: 202, description: '재발송 요청 접수'),
            new OA\Response(response: 422, description: 'email/affiliation 누락 또는 형식 오류 (VALIDATION_ERROR)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $email = $this->requireStringField($request, 'email');
        $affiliation = $this->requireAffiliationField($request);
        $this->userService->resendVerification($email, $affiliation->value);

        // 이메일 존재 여부를 노출하지 않도록 항상 202 로 응답한다.
        return $this->success(['message' => '인증 메일을 발송했습니다.'], statusCode: 202);
    }

    private function requireStringField(ServerRequestInterface $request, string $field): string
    {
        $value = $this->jsonInput($request)[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ValidationException([sprintf('%s 은(는) 필수입니다.', $field)]);
        }

        return $value;
    }

    private function requireAffiliationField(ServerRequestInterface $request): Affiliation
    {
        $value = $this->jsonInput($request)['affiliation'] ?? null;
        $affiliation = is_string($value) ? Affiliation::tryFrom($value) : null;
        if ($affiliation === null) {
            throw new ValidationException(['affiliation 은(는) 필수이며 유효한 소속이어야 합니다.']);
        }

        return $affiliation;
    }
```

- [ ] **Step 6: OpenAPI 스키마 갱신**

`src/OpenApi/Schema/EmailResendRequest.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 인증 메일 재발송 요청 본문.
 */
#[OA\Schema(
    schema: 'EmailResendRequest',
    required: ['email', 'affiliation'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '인증 메일을 재발송할 가입 이메일', example: 'user@aivance.test'),
        new OA\Property(property: 'affiliation', type: 'string', description: '가입한 소속 서비스 — 동일 이메일이 여러 소속에 가입돼 있을 수 있어 필수', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
    ],
)]
final class EmailResendRequest
{
}
```

- [ ] **Step 7: 전체 단위 테스트 + 정적분석 확인**

Run: `composer analyse && vendor/bin/phpunit tests/Unit`
Expected: 에러/실패 없음

- [ ] **Step 8: 커밋**

```bash
git add src/Service/UserService.php src/Controller/UserController.php src/OpenApi/Schema/EmailResendRequest.php tests/Unit/UserServiceTest.php
git commit -m "$(cat <<'EOF'
✨ feat: 인증메일 재발송에 affiliation 필수 파라미터 추가

동일 이메일이 여러 소속에 미인증 상태로 있을 수 있어, 재발송 대상을 (이메일, 소속)
조합으로 명확히 함.
EOF
)"
```

---

### Task 6: OpenAPI LoginRequest 스키마 갱신

**Files:**
- Modify: `src/OpenApi/Schema/LoginRequest.php`

**Interfaces:**
- Consumes: 없음(문서 전용, Task 2의 `LoginRequest` DTO와 별개 파일)

- [ ] **Step 1: 스키마 파일 교체**

`src/OpenApi/Schema/LoginRequest.php` 전체를 아래로 교체:

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 컴포넌트 — 로그인 요청 본문.
 */
#[OA\Schema(
    schema: 'LoginRequest',
    description: '로그인 자격증명',
    required: ['email', 'password', 'affiliation'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', description: '가입한 이메일(로그인 ID)', example: 'admin@aivance.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', description: '비밀번호', example: 'password1234!'),
        new OA\Property(property: 'affiliation', type: 'string', description: '로그인할 소속 서비스 — 동일 이메일이 여러 소속에 독립 계정으로 가입돼 있을 수 있어 필수', enum: ['aicura', 'aicopia', 'aicreo', 'aivance', 'ailicet'], example: 'aivance'),
    ],
)]
final class LoginRequest
{
}
```

- [ ] **Step 2: OpenAPI 스펙 생성 확인** (스펙이 깨지지 않는지만 확인 — HTTP 서버 기동 없이 어트리뷰트 스캔 검증)

Run: `vendor/bin/phpunit tests/Unit` (기존에 OpenAPI 스펙 유효성을 검사하는 단위테스트가 있다면 여기서 함께 통과 확인됨. 없다면 이 스텝은 스킵하고 `composer analyse`로 대체)
Expected: 에러 없음

- [ ] **Step 3: 커밋**

```bash
git add src/OpenApi/Schema/LoginRequest.php
git commit -m "$(cat <<'EOF'
📝 docs: 로그인 API OpenAPI 스키마에 affiliation 필드 반영
EOF
)"
```

---

### Task 7: E2E — 다중 소속 가입/로그인 시나리오 (Feature 테스트)

**Files:**
- Modify: `tests/Feature/AuthJourneyTest.php`

**Interfaces:**
- Consumes: Task 1~6에서 완성된 전체 파이프라인(`POST /api/v1/users`, `POST /api/v1/tokens`, `POST /api/v1/users/verify/resend`)

- [ ] **Step 1: 기존 헬퍼에 affiliation 반영**

`tests/Feature/AuthJourneyTest.php`의 `login()`, `registerPayload()` 헬퍼와 `testResendVerificationAlwaysAccepts` 테스트를 아래로 교체:

```php
    private function login(string $email, ?string $password = null, string $affiliation = 'aivance'): ResponseInterface
    {
        return $this->handle('POST', '/api/v1/tokens', [], [
            'email' => $email,
            'password' => $password ?? self::PASSWORD,
            'affiliation' => $affiliation,
        ]);
    }
```

`registerPayload()`는 기존 그대로 유지(이미 `affiliation` => `'aivance'` 포함).

```php
    public function testResendVerificationAlwaysAccepts(): void
    {
        // 미가입 이메일 — 존재 여부를 노출하지 않으려 항상 202
        $unknown = $this->handle('POST', '/api/v1/users/verify/resend', [], [
            'email' => 'nobody-' . bin2hex(random_bytes(4)) . '@aivance.test',
            'affiliation' => 'aivance',
        ]);
        self::assertSame(202, $unknown->getStatusCode());

        // 가입했으나 미인증인 이메일 — 새 토큰을 큐에 적재하고 202
        [$email] = $this->register();
        $resend = $this->handle('POST', '/api/v1/users/verify/resend', [], ['email' => $email, 'affiliation' => 'aivance']);
        self::assertSame(202, $resend->getStatusCode());
    }
```

- [ ] **Step 2: 새 다중 소속 시나리오 테스트 추가**

파일 끝 `// ── 추가 시나리오 (핵심 negative) ─────` 섹션의 `testResendVerificationAlwaysAccepts` 바로 뒤에 추가:

```php
    public function testSameEmailCanRegisterAndLoginToTwoDifferentAffiliations(): void
    {
        $email = 'e2e-multi-' . bin2hex(random_bytes(6)) . '@aivance.test';
        $this->createdEmails[] = $email;

        // 1) aivance 소속으로 가입·인증·로그인
        $registerAivance = $this->handle('POST', '/api/v1/users', [], $this->registerPayload($email));
        self::assertSame(201, $registerAivance->getStatusCode(), (string) $registerAivance->getBody());
        $verifyTokenAivance = $this->popVerificationToken();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyTokenAivance]);

        $loginAivance = $this->login($email, null, 'aivance');
        self::assertSame(201, $loginAivance->getStatusCode());

        // 2) 같은 이메일로 ailicet 소속에도 독립적으로 가입 가능해야 한다(409가 아니어야 함)
        $ailicetPayload = $this->registerPayload($email);
        $ailicetPayload['affiliation'] = 'ailicet';
        $registerAilicet = $this->handle('POST', '/api/v1/users', [], $ailicetPayload);
        self::assertSame(201, $registerAilicet->getStatusCode(), (string) $registerAilicet->getBody());

        // 3) ailicet 계정은 아직 이메일 미인증 — ailicet 소속으로 로그인하면 403(EMAIL_NOT_VERIFIED)
        $loginAilicetBeforeVerify = $this->login($email, null, 'ailicet');
        self::assertSame(403, $loginAilicetBeforeVerify->getStatusCode());
        self::assertSame('EMAIL_NOT_VERIFIED', $this->decode($loginAilicetBeforeVerify)['code']);

        // 4) ailicet 인증 후 로그인 성공 — aivance 로그인과 별개의 토큰이 발급된다
        $verifyTokenAilicet = $this->popVerificationToken();
        $this->handle('POST', '/api/v1/users/verify', [], ['token' => $verifyTokenAilicet]);
        $loginAilicet = $this->login($email, null, 'ailicet');
        self::assertSame(201, $loginAilicet->getStatusCode());
        self::assertNotSame(
            $this->decode($loginAivance)['data']['access_token'],
            $this->decode($loginAilicet)['data']['access_token'],
        );
    }

    public function testLoginWithAffiliationTheEmailIsNotRegisteredInReturns401(): void
    {
        // aivance로만 가입·인증했는데 ailicet 소속으로 로그인 시도 → INVALID_CREDENTIALS(계정 존재 노출 안 함)
        $tokens = $this->registerVerifyLogin();
        self::assertNotEmpty($tokens['access_token']);

        [$email] = [$this->createdEmails[array_key_last($this->createdEmails)]];
        $response = $this->login($email, null, 'ailicet');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->decode($response)['code']);
    }
```

기존 `testDuplicateRegistrationReturns409`(동일 이메일 + 동일 소속 재가입 → 409)는 이미 "같은 소속 내 중복은 여전히 막힌다"를 검증하고 있으므로 그대로 두고 새로 추가하지 않는다(중복 테스트 방지, YAGNI).

- [ ] **Step 3: 테스트 실행 (DB 필요)**

Run: `vendor/bin/phpunit tests/Feature/AuthJourneyTest.php`
Expected: PASS (전체). DB 미가용 환경이면 `markTestSkipped`로 스킵됨 — 이 경우 Task 8에서 CI로 최종 확인.

- [ ] **Step 4: 커밋**

```bash
git add tests/Feature/AuthJourneyTest.php
git commit -m "$(cat <<'EOF'
✅ test: 동일 이메일 다중 소속 가입·로그인 E2E 시나리오 추가

같은 이메일로 두 소속에 각각 가입·인증·로그인 성공, 가입 안 한 소속으로 로그인 시
401, 같은 소속 중복 가입 시 409를 검증.
EOF
)"
```

---

### Task 8: 전체 검증 및 마무리

**Files:** 없음(검증 전용)

- [ ] **Step 1: 전체 게이트 실행**

Run: `composer check`
Expected: `cs-check` → `analyse`(PHPStan level 8) → `test`(PHPUnit, 커버리지 포함) 순서로 전부 통과.

- [ ] **Step 2: `grep`으로 시그니처 변경 누락 호출부 점검**

Run:
```bash
grep -rn "findActiveByEmail(\|emailExists(\|resendVerification(\|new LoginRequest(" src/ tests/ --include="*.php" | grep -v "worktrees"
```
Expected: 출력되는 모든 호출부가 이번 태스크들에서 이미 갱신한 인자 개수(affiliation 포함)와 일치. 불일치가 있으면 해당 파일을 마저 수정하고 관련 테스트 재실행.

- [ ] **Step 3: 최종 확인 커밋** (수정 사항이 있었을 경우에만)

```bash
git status --short
```
변경 파일이 있다면 파일명에 맞는 커밋 메시지로 커밋. 없으면 이 스텝은 스킵.
