# 코드 스타일 규칙

> [CLAUDE.md](../../CLAUDE.md) 에서 `@import` 로 자동 로딩되는 코드 스타일 전용 규칙 문서.
> 보안 관련 금지 사항은 [security.md](security.md), API 설계는 [api-design.md](api-design.md) 참고.

## 네이밍 규칙

### PHP

| 대상 | 규칙 | 예시 |
|------|------|------|
| 클래스 | PascalCase | `TokenController`, `JwtIssuer` |
| 인터페이스 | PascalCase + `Interface` | `TokenStoreInterface`, `ClockInterface` |
| 추상 클래스 | `Base` 접두어 | `BaseController` |
| 메서드 | camelCase | `issueAccessToken()`, `verifyRefreshToken()` |
| 변수 | camelCase | `$accessToken`, `$userId` |
| 프로퍼티 | camelCase | `$refreshTtl`, `$jwtSecret` |
| 상수 | UPPER_SNAKE_CASE | `MAX_RETRY`, `DEFAULT_TTL` |
| 배열 키 | snake_case | `$data['access_token']`, `$payload['user_id']` |
| 파일명 | 클래스와 동일 | `TokenController.php` |

### DB

| 대상 | 규칙 | 예시 |
|------|------|------|
| 테이블 | snake_case · 복수형 | `users`, `refresh_tokens`, `login_logs` |
| 컬럼 | snake_case | `created_at`, `expires_at` |
| PK | `id` | `id` |
| FK | `{단수테이블명}_id` | `user_id` |
| 불리언 | `is_` 접두어 | `is_active`, `is_revoked` |
| 타임스탬프 | `created_at`, `updated_at`, `deleted_at` | |
| 일반 인덱스 | `idx_{테이블}_{컬럼}` | `idx_refresh_tokens_user_id` |
| 유니크 인덱스 | `uniq_{테이블}_{컬럼}` | `uniq_users_email` |
| Pivot 테이블 | 두 테이블 알파벳순 · 단수 | `role_user` |

## 코딩 규칙

- PSR-12 준수 (PHP-CS-Fixer 로 강제)
- 입력값은 반드시 `respect/validation` 으로 검증 후 사용 — 원시 요청 데이터 직접 신뢰 금지
- SQL 은 **PDO prepared statement + 플레이스홀더 바인딩**만 사용 (raw 문자열 조합 금지)
- 시크릿은 `.env` 에서만 관리 (`$_ENV['KEY']` / 설정 객체 경유)
- 출력은 JSON 인코딩 시 `JSON_THROW_ON_ERROR` 사용
- Repository 반환 타입은 **배열(`array<string, mixed>`)** 로 통일 — DTO 매핑은 Service 에서

## PHP 절대 금지 — 코드 품질

| 금지 | 이유 |
|------|------|
| `@` 에러 억제 연산자 | 에러를 숨겨 디버깅 불가 |
| `extract($array)` | 변수 충돌·추적 불가 |
| `global $변수` | 상태 추적 불가, 테스트 불가 |
| `die()` / `exit()` 비즈니스 로직 안에 | 응답 흐름 단절, 테스트 불가 |
| 함수 하나에 100줄 이상 | 단일 책임 원칙 위반 |
| 의미 없는 변수명 (`$a`, `$tmp`, `$data2`) | 가독성 저하 |
| 주석으로 코드 비활성화 후 방치 | 죽은 코드 |
| `var_dump()` / `print_r()` 커밋 | 디버그 코드 노출 |

## PHP 절대 금지 — PHP 특성 함정

| 금지 | 이유 | 대신 |
|------|------|------|
| `==` 타입 비교 | `0 == "a"` → true | `===` 사용 |
| `intval()` 없이 문자열을 숫자로 연산 | 타입 오염 | 명시적 형변환 또는 타입 선언 |
| 타입 선언 없는 함수 파라미터 | PHPStan 레벨 8 통과 불가 | `string $id`, `int $count` 명시 |
| `null` 반환과 `false` 반환 혼용 | 호출부 처리 혼란 | 반환 타입 통일 |
| `catch` 후 예외 무시 | 버그가 조용히 삼켜짐 | 최소한 로깅 |

## PHP 절대 금지 — 순수 PHP 아키텍처 한정

| 금지 | 이유 |
|------|------|
| Controller 에 비즈니스 로직 작성 | Service 로 위임 |
| Controller·Service 에서 SQL 직접 실행 | 데이터 접근은 Repository 로 격리 |
| `PDO`·`Redis` 를 전역/싱글턴으로 직접 참조 | DI 컨테이너 경유 (생성자 주입) |
| `new Service()` 직접 인스턴스화 | 컨테이너가 주입 — 수동 `new` 금지 |
| 미들웨어 밖에서 인증 상태 조작 | 인증은 `JwtAuthMiddleware` 한 곳으로 |
| `public/` 밖 파일을 웹에 노출 | 진입점은 `public/index.php` 하나만 |
| `.env` 를 저장소에 커밋 | 시크릿 노출 |

## PHP 모던 스타일 (8.4+)

상태·타입 관리는 배열·상수 대신 **readonly DTO**·**Backed Enum**을 우선한다.

```php
// ✅ readonly DTO — 요청·응답 데이터 매핑
final readonly class CreateUserRequest
{
    public function __construct(
        public string $email,
        public string $name,
        public UserRole $role = UserRole::Member,
    ) {}
}

// ✅ Backed Enum — 상태·타입은 Enum으로
enum UserRole: string
{
    case Admin  = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin  => '관리자',
            self::Member => '일반회원',
        };
    }
}

// ❌ 금지 — 배열·define()로 상태/타입 관리
define('ROLE_ADMIN', 1);
```

- 메서드·프로퍼티에 타입 선언(return type 포함) 완전 적용 (PHPStan 레벨 8 전제)
- `match` 표현식 우선 (`switch` 지양)
- DTO는 `final readonly`, 정적 팩토리(`fromRequest()`, `fromArray()`)로 생성

## 레이어 책임 (Controller · Service · Repository)

- **Controller는 얇게(thin)**: 입력 검증 → Service 호출 → 응답 반환만 수행
- 비즈니스 로직이 Controller 에 생기면 즉시 Service 로 추출
- **하나의 Service 메서드 = 하나의 유스케이스**
- **DB 트랜잭션은 Service 레이어**에서 관리 (`$pdo->beginTransaction()` / `commit()` / `rollBack()`)
- 데이터 접근은 **Repository** 로 격리 — Controller·Service 는 SQL 을 직접 다루지 않는다
- 모든 협력 객체는 **DI 컨테이너 생성자 주입**으로 받는다 (직접 `new` 금지)

```php
// ✅ 얇은 컨트롤러
final class TokenController extends BaseController
{
    public function __construct(private readonly AuthService $authService) {}

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $dto    = LoginRequest::fromRequest($request);
        $result = $this->authService->issueTokens($dto);
        return $this->success($result, statusCode: 201);
    }
}
```

## 도메인 예외 처리

- 도메인 예외는 `src/Exception/` 에 커스텀 클래스로 정의
- 예외는 **HTTP 상태코드 + 에러 코드(문자열)** 를 반드시 포함 (에러 코드 네이밍 규칙 준수)
- 전역 `ErrorHandlerMiddleware`(PSR-15 파이프라인 최상단)가 예외를 잡아 표준 에러 응답으로 변환

```php
// src/Exception/DomainException.php
abstract class DomainException extends \RuntimeException
{
    abstract public function httpStatusCode(): int;
    abstract public function errorCode(): string;   // 예: 'INVALID_CREDENTIALS'
}
```
