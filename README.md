# AITessera

> **Your key to trusted access.** — AIvance 제품군을 위한 JWT 기반 인증·인가 API

AITessera 는 **프레임워크 없는 순수 모던 PHP(8.4+)** 로 구성한 REST API 단일 프로젝트다.
검증된 PSR 표준 라이브러리를 조합해 만들며, JWT·암호화 등 보안 핵심은 직접 구현하지 않고
신뢰받는 라이브러리에 위임한다.

이름은 고대 로마의 신원 확인 증표(*tessera*)에서 따왔다.

---

## 기술 스택

| 영역 | 채택 |
|------|------|
| 언어 | PHP 8.4+ (`readonly`·enum·완전한 타입 선언) |
| HTTP 메시지 | PSR-7 (`nyholm/psr7`) |
| 미들웨어 | PSR-15 (`relay/relay`) |
| 라우팅 | `nikic/fast-route` |
| DI 컨테이너 | `php-di/php-di` (PSR-11) |
| JWT | `lcobucci/jwt` (HS256, `alg` 고정) |
| DB | PDO (prepared statement) · 마이그레이션 `robmorgan/phinx` |
| 캐시·큐 | Redis (`predis/predis`) |
| 입력 검증 | `respect/validation` |
| 환경변수 | `vlucas/phpdotenv` |
| API 문서 | `zircote/swagger-php` (예정) |
| 정적 분석 | PHPStan level 8 |
| 코드 스타일 | PHP-CS-Fixer (PSR-12) |
| 테스트 | PHPUnit |

---

## 요구 사항

- PHP **8.4+** (확장: `pdo_mysql`, `mbstring`, `intl`, `redis`, `curl`, `dom`, `xml`, `tokenizer`)
- Composer 2.x
- MySQL 8.0
- Redis 7 (캐시·큐)

---

## 로컬 환경 설정

```bash
cp .env.example .env      # 아래 필수 키 설정
composer install
php bin/console migrate   # 마이그레이션 실행 (phinx 래퍼)
composer serve            # 개발 서버 (http://localhost:9300)
```

`.env` 필수 키(발췌):

```env
APP_ENV=local
DB_HOST=127.0.0.1
DB_NAME=aitessera
DB_USER=aitessera
DB_PASS=
REDIS_HOST=127.0.0.1
JWT_SECRET=<32자 이상 랜덤 문자열>
JWT_ACCESS_TTL=900        # Access 토큰 15분
JWT_REFRESH_TTL=1209600   # Refresh 토큰 14일
```

> 시크릿은 **절대 저장소에 커밋하지 않는다.** 운영은 AWS SSM / Secrets Manager 를 사용한다.

### 개발용 테스트 계정

마이그레이션은 시더를 자동 실행하지 않는다. 수동 테스트 계정이 필요하면:

```bash
php bin/console seed:run   # admin@aivance.test / password1234!
```

---

## 커맨드

```bash
composer serve      # 개발 서버 (php -S localhost:9300 -t public)
composer test       # PHPUnit
composer analyse    # PHPStan (level 8)
composer cs-fix     # PHP-CS-Fixer 자동 정렬
composer cs-check   # PHP-CS-Fixer dry-run
composer check      # cs-check + analyse + test 순차 실행
php bin/console <command>   # CLI (migrate·rollback·seed:run)
```

---

## 아키텍처

### 요청 처리 흐름

```
public/index.php
  → .env 로딩 → DI 컨테이너 빌드
  → PSR-15 미들웨어 파이프라인 (Relay)
      [ ErrorHandler → Cors → RateLimit → JwtAuth → RouteDispatch ]
  → Controller → Service → Repository(PDO)
  → PSR-7 Response
```

- **인증은 라우팅보다 먼저** 실행된다. 미인증 요청은 경로 존재 여부와 무관하게 `401` 을
  받는다(존재 노출 차단). `404` 는 인증을 통과한 뒤에만 관측된다.
- **DB 는 지연 연결**된다(`ConnectionInterface`/`Database`). 검증·인증에서 먼저 실패하는
  요청은 DB 를 연결하지 않는다.

### 레이어 책임

- **Controller** — 얇게 유지: 입력 검증 → Service 호출 → 응답 반환
- **Service** — 비즈니스 로직·트랜잭션 관리 (하나의 메서드 = 하나의 유스케이스)
- **Repository** — PDO 데이터 접근 격리 (반환은 배열, DTO 매핑은 Service)

### 디렉토리

| 경로 | 용도 |
|------|------|
| `public/index.php` | 유일한 진입점(front controller) |
| `src/Controller/` | HTTP 요청 처리 |
| `src/Service/` | 비즈니스 로직 |
| `src/Repository/` | PDO 데이터 접근 |
| `src/Middleware/` | PSR-15 미들웨어 |
| `src/Domain/` | DTO·Enum·도메인 모델 |
| `src/Exception/` | 도메인 예외 |
| `src/Support/` | 공용 유틸 (JWT·응답·DB 연결 등) |
| `config/` | 컨테이너·라우트 정의 |
| `migrations/` | phinx 마이그레이션·시더 |
| `bin/console` | CLI 진입점 |
| `tests/` | Unit·Feature 테스트 |

---

## API

### 응답 포맷

```jsonc
// 성공
{ "status": "success", "data": { ... }, "meta": { ... } }
// 실패
{ "status": "error", "code": "ERROR_CODE", "message": "..." }
```

### 엔드포인트

| 메서드 | 경로 | 인증 | 설명 |
|--------|------|:----:|------|
| `GET` | `/health` | — | 헬스체크(라이브니스) |
| `POST` | `/api/v1/tokens` | — | 로그인 → Access + Refresh 발급 (201) |
| `POST` | `/api/v1/tokens/refresh` | — | Refresh 회전 재발급 (200) |
| `DELETE` | `/api/v1/tokens` | — | 로그아웃 — Refresh 무효화 (204) |
| `GET` | `/api/v1/me` | ✅ | 현재 로그인 사용자 조회 |

보호 엔드포인트는 `Authorization: Bearer <access_token>` 헤더가 필요하다.

#### 예시 — 로그인

```bash
curl -X POST http://localhost:9300/api/v1/tokens \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@aivance.test","password":"password1234!"}'
```

```jsonc
{
  "status": "success",
  "data": {
    "access_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 900,
    "refresh_token": "3f9a..."
  }
}
```

### 인증 설계

- **Access Token(JWT, 15분) + Refresh Token(불투명 문자열, 14일)** 분리
- Refresh 토큰은 **SHA-256 해시로만 DB 에 저장**(평문 미보관)
- **회전(rotation)** — Refresh 사용 시 이전 토큰 폐기 + 새 토큰 발급(트랜잭션)
- **재사용 감지** — 이미 폐기된 Refresh 토큰이 다시 제시되면 탈취로 간주하고
  해당 사용자의 **모든 토큰을 무효화**
- 알고리즘 HS256 고정(`alg:none` 우회 차단), 비밀번호 `password_hash()`(Argon2id)

### 에러 코드

| 코드 | 상황 | HTTP |
|------|------|:----:|
| `UNAUTHORIZED` | 인증 토큰 없음 | 401 |
| `INVALID_TOKEN` | 토큰 형식·서명 오류 | 401 |
| `TOKEN_EXPIRED` | 토큰 만료 | 401 |
| `INVALID_CREDENTIALS` | 이메일·비밀번호 불일치 | 401 |
| `EMAIL_NOT_VERIFIED` | 이메일 미인증 | 403 |
| `VALIDATION_ERROR` | 유효성 검사 실패 | 422 |
| `NOT_FOUND` | 리소스 없음 | 404 |
| `METHOD_NOT_ALLOWED` | 허용되지 않은 메서드 | 405 |
| `INTERNAL_ERROR` | 서버 내부 오류 | 500 |

---

## 데이터베이스

### `users`

이메일(로그인 ID)·비밀번호·소속(`aicura`·`aicopia`·`aicreo`·`aivance`·`ailicet`)을 담는다.
가입 시 이메일 인증(`email_verified_at`), 약관·제3자 정보제공 동의, 이름·연락처(필수)·회사(선택)를
보관하며, **소속별 부가 항목은 `profile`(JSON) 한 컬럼**에 저장한다. 소프트 삭제(`deleted_at`) 지원.

### `refresh_tokens`

Refresh 토큰의 SHA-256 해시·만료(`expires_at`)·무효화(`revoked_at`)를 관리한다.
`user_id` 는 `users(id)` 를 `CASCADE` 로 참조한다.

---

## 테스트

```bash
composer test
```

- `tests/Unit/` — 외부 의존성 Mock (AuthService·JwtIssuer·LoginRequest 등)
- `tests/Feature/` — 파이프라인 통합 (라우팅·인증 관통)
- 커버리지 목표: Service 레이어 80% 이상

---

## CI / CD

- **CI** (`.github/workflows/ci.yml`) — `dev`·`main` push/PR 마다 MySQL·Redis 컨테이너를
  띄우고 `cs-check → PHPStan(level 8) → migrate → PHPUnit` 순차 검증
- **CD** (`.github/workflows/deploy.yml`) — `main` push 시 SSH 자동 배포

### Git 워크플로우

```
feature/* → (Squash merge) → dev → (Merge commit) → main
```

`main`·`dev` 직접 push 금지. 자세한 규칙은 [`CLAUDE.md`](CLAUDE.md) 참고.

---

## 구현 현황

- [x] 프로젝트 스캐폴딩 (Composer·PHPStan·PHP-CS-Fixer·PHPUnit·phinx)
- [x] CI 워크플로우 (GitHub Actions)
- [x] DI 컨테이너 + PSR-15 미들웨어 파이프라인
- [x] 인증 엔드포인트 (로그인·Refresh 회전·로그아웃)
- [x] 회원가입 + 이메일 인증 발송
- [x] RateLimit 미들웨어 (brute-force 방어)
- [ ] Swagger UI (`/api/docs`)
- [ ] 로그 수집 파이프라인 (큐 컨슈머)
