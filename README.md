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
| API 문서 | `zircote/swagger-php`(v5) + RapiDoc UI (`/api/docs`, 좌측 API 목록) |
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
```

`.env` 필수 키(발췌):

```env
APP_ENV=local
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=aitessera
DB_USER=aitessera
DB_PASS=<DB 비밀번호>
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
JWT_ALGO=HS256            # 서명 알고리즘: HS256(대칭키) 또는 RS256(비대칭키)
JWT_SECRET=<32자 이상 랜덤 문자열>   # HS256 사용 시 필수
JWT_ACCESS_TTL=900        # Access 토큰 15분
JWT_REFRESH_TTL=1209600   # Refresh 토큰 14일
```

> 시크릿은 **절대 저장소에 커밋하지 않는다.** 운영은 AWS SSM / Secrets Manager 를 사용한다.
>
> RS256(비대칭키)으로 전환하려면 아래 [JWT 서명 키](#jwt-서명-키-rs256) 를 참고한다.

### Redis 실행

캐시·레이트리밋·큐에 사용한다. macOS(Homebrew) 기준:

```bash
brew install redis
brew services start redis     # 백그라운드 실행 (재부팅 후 자동 시작)
redis-cli ping                # → PONG (정상)

brew services stop redis      # 중지
# 서비스로 등록하지 않고 일회성 실행: redis-server
```

> Linux 는 `sudo systemctl start redis`, Docker 는 `docker run -p 6379:6379 redis:7`.

### MySQL 준비

MySQL 8.0+ 서버가 실행 중이어야 하고, `.env` 의 `DB_*` 계정·DB 가 있어야 한다.
최초 1회 DB·유저 생성(예):

```sql
CREATE DATABASE aitessera CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aitessera'@'localhost' IDENTIFIED BY '<DB_PASS>';
GRANT ALL PRIVILEGES ON aitessera.* TO 'aitessera'@'localhost';
FLUSH PRIVILEGES;
```

### 마이그레이션

`migrations/` 의 스키마를 적용한다(phinx 래퍼, 설정은 `phinx.php`).

```bash
php bin/console migrate    # 대기 중인 마이그레이션 실행
php bin/console rollback    # 마지막 마이그레이션 롤백
```

### 시더 · 테스트 계정

배포·마이그레이션은 시더를 **자동 실행하지 않는다.** 개발/수동 테스트용 관리자 계정이 필요하면:

```bash
php bin/console seed:run   # 재실행 안전 (이미 있으면 건너뜀)
```

| 항목 | 값 |
|------|-----|
| 이메일 | `admin@aivance.test` |
| 비밀번호 | `password1234!` |
| 소속 | `aivance` (이메일 인증 완료 · 활성) |

로그인 확인:

```bash
curl -X POST http://localhost:9300/api/v1/tokens \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@aivance.test","password":"password1234!"}'
```

### JWT 서명 키 (RS256)

기본 서명 알고리즘은 **HS256**(대칭키)이며 `JWT_SECRET` 하나로 서명·검증한다.
발급자(개인키)와 검증자(공개키)를 분리하려면 **RS256**(비대칭키)으로 전환한다.

RSA 키페어를 생성한다(기본 `var/keys/` 에 생성 · 개인키는 자동으로 `0600` 권한):

```bash
php bin/console jwt:keygen           # var/keys/jwt_{private,public}.pem 생성
php bin/console jwt:keygen --force    # 기존 키가 있어도 덮어쓰기
```

생성 후 안내된 값을 `.env` 에 설정한다:

```env
JWT_ALGO=RS256
JWT_PRIVATE_KEY_PATH=var/keys/jwt_private.pem   # 서명(발급)용 개인키
JWT_PUBLIC_KEY_PATH=var/keys/jwt_public.pem     # 검증용 공개키
# JWT_PRIVATE_KEY_PASSPHRASE=                    # 개인키에 암호가 걸려 있을 때만
```

> - **개인키는 절대 커밋하지 않는다.** `var/keys/` · `*.pem` 은 `.gitignore` 로 차단되어 있다.
> - 운영은 키페어를 저장소가 아니라 **AWS SSM Parameter Store / Secrets Manager** 로 배치하고 경로만 지정한다.
> - `JWT_ALGO=RS256` 인데 키 파일을 읽을 수 없으면 **부팅 시 즉시 실패**(fail-fast)한다.
> - Access 토큰은 15분 단기라 HS256↔RS256 전환 시 기존 토큰 영향이 거의 없다.

#### JWKS — 공개키 자동 확인 (외부 검증자용)

RS256 공개키를 외부 서비스가 **자동으로 내려받아 검증**할 수 있도록 JWKS(JSON Web Key
Set · RFC 7517) 엔드포인트를 제공한다. 공개키 파일을 수동 배포할 필요가 없다.

```bash
curl http://localhost:9300/.well-known/jwks.json   # 표준 well-known 경로
curl http://localhost:9300/api/v1/jwks.json         # 버전 경로 별칭 (동일 응답)
```

```json
{ "keys": [ { "kty": "RSA", "use": "sig", "alg": "RS256", "kid": "…", "n": "…", "e": "AQAB" } ] }
```

> - 발급 토큰 헤더에는 공개키에서 파생한 `kid`(RFC 7638 thumbprint)가 실려, 소비자가 JWKS 의
>   여러 키 중 검증 키를 매칭·회전할 수 있다.
> - **HS256(대칭키)에서는 시크릿을 절대 노출하지 않고 빈 키셋(`{"keys":[]}`)을 반환**한다.
> - 응답은 `application/jwk-set+json` · `Cache-Control: public, max-age=3600` 로 캐시 가능하다.

### 개발 서버 · 큐 워커

```bash
composer serve             # http://localhost:9300
php bin/console mail:work   # 메일 큐 컨슈머 (큐 비우고 종료)
php bin/console log:work    # 로그 큐 컨슈머 (큐 비우고 종료)
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
php bin/console <command>   # CLI (migrate·rollback·seed:run·jwt:keygen·mail:work·log:work·security:scan·report:daily·report:security)
```

---

## 아키텍처

### 요청 처리 흐름

```
public/index.php
  → .env 로딩 → DI 컨테이너 빌드
  → PSR-15 미들웨어 파이프라인 (Relay)
      [ ErrorHandler → TrailingSlash → Cors → RateLimit → JwtAuth → RouteDispatch ]
  → Controller → Service → Repository(PDO)
  → PSR-7 Response
```

- **인증은 라우팅보다 먼저** 실행된다. 미인증 요청은 경로 존재 여부와 무관하게 `401` 을
  받는다(존재 노출 차단). `404` 는 인증을 통과한 뒤에만 관측된다.
- **DB 는 지연 연결**된다(`ConnectionInterface`/`Database`). 검증·인증에서 먼저 실패하는
  요청은 DB 를 연결하지 않는다.
- **끝 슬래시 정규화** — `TrailingSlashMiddleware` 가 `/api/docs/` 를 `/api/docs` 로 다시 써서
  끝 슬래시 유무와 무관하게 동작하게 한다.

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
| `src/Support/` | 공용 유틸 (JWT·DB 연결·큐·메일·응답 등) |
| `src/Console/` | 큐 컨슈머 CLI 워커 (`ProcessMailQueue`·`ProcessLogQueue`) |
| `src/OpenApi/Schema/` | OpenAPI 재사용 스키마 컴포넌트 |
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
| `GET` | `/api/docs` | — | API 문서 UI (RapiDoc, 좌측 API 목록) |
| `GET` | `/api/v1/openapi.json` | — | OpenAPI 스펙 (JSON) |
| `POST` | `/api/v1/logs` | — | 클라이언트 로그 수집 (비동기, 202) |

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
- 서명 알고리즘 **HS256(기본)/RS256 선택**(`JWT_ALGO`) · 검증 시 알고리즘 고정으로 `alg:none` 우회 차단
  — RS256 설정은 [JWT 서명 키](#jwt-서명-키-rs256) 참고
- 비밀번호 `password_hash()`(Argon2id)

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
- [x] Swagger UI (`/api/docs`)
- [x] 로그 수집 파이프라인 (큐 컨슈머)
- [x] AI 로그 자동 분류·요약 — `log:work` 컨슈머가 error/critical 로그를 Claude API 로 분류·요약 (#50)
- [x] AI 일일 이상징후 리포트 — `report:daily` 가 로그를 집계·분석해 메일 큐로 발송 (#51)
- [x] AI 로그인 이상 탐지 — `security:scan` 이 로그인 이벤트를 규칙 + AI 로 스코어링 (#52)
- [x] 로그인 이상 알림/리포트 — 임계값 초과 시 실시간 메일 알림(계정+IP 쿨다운) + `report:security` 일일 보안 요약

> AI 기능은 요청 사이클 밖(큐 컨슈머·스케줄러)에서만 동작하며, `ANTHROPIC_API_KEY` 미설정 시
> 규칙 기반으로 동작(이상 탐지)하거나 건너뛴다(분류·리포트). 관련 `.env` 키는 `.env.example` 의
> `AI 로그 분류` 섹션 참고. CLI: `php bin/console log:work | report:daily | security:scan | report:security`.
> 보안 알림/리포트 수신자는 `SECURITY_ALERT_RECIPIENT`(미설정 시 `LOG_REPORT_RECIPIENT` 폴백)로 설정한다.

### 운영 활성화 — 로그인 이상 알림·보안 리포트

로그인 이상 탐지 결과를 메일로 받으려면 **수신자 설정 + 워커 주기 실행 + 메일 큐 소비** 세 가지를 갖춘다.
워커는 모두 "큐를 비우고 종료"하는 원샷 방식이라 cron/systemd timer 로 주기 기동한다.

1. **수신자 설정** — `.env` 에 아래를 추가한다. 비우면 발송을 건너뛴다(무설정 시 안전).

   ```env
   SECURITY_ALERT_RECIPIENT=soc@example.com   # 미설정 시 LOG_REPORT_RECIPIENT 로 폴백
   ANOMALY_ALERT_COOLDOWN=1800                # 실시간 알림 쿨다운(초), 기본 30분
   # ANTHROPIC_API_KEY=...                    # 있으면 일일 리포트를 AI 서술, 없으면 통계 폴백
   ```

2. **워커 주기 실행 (cron 예시)** — 실시간 알림은 `security:scan`(로그인 이벤트 스코어링) 단계에서
   임계값 초과 시 메일 큐에 적재되고, 일일 요약은 `report:security` 가 하루 1회 생성한다. 두 워커가
   적재한 메일은 `mail:work` 가 실제 발송한다.

   ```cron
   * * * * *   cd /srv/aitessera && php bin/console security:scan   # 실시간 이상 스코어링·알림 적재
   */2 * * * * cd /srv/aitessera && php bin/console mail:work        # 메일 큐 발송(알림·리포트 공용)
   10 9 * * *  cd /srv/aitessera && php bin/console report:security  # 전일 보안 요약 리포트(하루 1회)
   ```

3. **동작 원리** — 같은 계정+IP 의 실시간 알림은 `ANOMALY_ALERT_COOLDOWN` 내 최초 1회만 발송해
   brute-force 시 메일 폭주를 막는다(Redis `SET NX EX`). 임계값 초과 이벤트는 `var/logs/security/`
   에도 계속 append 되므로, 메일 미설정 환경에서도 파일 감사는 그대로 유지된다.
