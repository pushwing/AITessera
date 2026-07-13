# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

AITessera — Secure JWT-based authentication & authorization API for the AIvance service suite.
**프레임워크 없는 순수 모던 PHP(8.3+)** 기반 REST API 단일 프로젝트. 검증된 표준 라이브러리를
조합해 구성하며, JWT·암호화 등 보안 핵심은 절대 직접 구현하지 않고 신뢰받는 라이브러리에 위임한다.

> **공통 규칙은 전역 [`~/.claude/CLAUDE.md`](~/.claude/CLAUDE.md) 에서 자동 상속**된다(언어·Git 워크플로우·보안·코드 스타일·테스트·API 설계·부하 분산·로그 파이프라인·PHP LSP). 이 문서는 **AITessera 저장소 전용**(프레임워크리스 스택·아키텍처·CI/CD) 규칙만 정의한다.
>
> 프레임워크리스 프로젝트이므로 전역 규칙의 CI4 표현은 다음으로 매핑한다: `esc()`→`htmlspecialchars`/JSON, Query Builder→PDO prepared, `model()`→DI 주입, `.env`→`vlucas/phpdotenv`.

## 기술 스택

프레임워크 대신 PSR 표준 기반의 경량 라이브러리를 조합한다.

| 영역 | 채택 | 비고 |
|------|------|------|
| **언어** | PHP 8.4+ | `readonly`, enum, 완전한 타입 선언 |
| **의존성 관리** | Composer | PSR-4 오토로딩 |
| **HTTP 메시지** | PSR-7 (`nyholm/psr7`) | 프레임워크 독립 |
| **미들웨어 디스패처** | PSR-15 (`relay/relay`) | 인증·CORS·에러 핸들링 파이프라인 |
| **라우팅** | `nikic/fast-route` | 초경량 라우터 |
| **DI 컨테이너** | `php-di/php-di` (PSR-11) | 생성자 주입 |
| **JWT** | `lcobucci/jwt` | 직접 구현 금지 · `alg` 고정 |
| **DB 접근** | PDO (prepared statement) | raw 문자열 조합 금지 |
| **마이그레이션** | `robmorgan/phinx` | `phinx.php` 정의 |
| **환경변수** | `vlucas/phpdotenv` | `.env` 로딩 |
| **입력 검증** | `respect/validation` | 컨트롤러 진입 시 강제 |
| **레이트 리밋** | `symfony/rate-limiter` | brute-force 방어 |
| **캐시·큐** | Redis (`predis/predis`) | 캐시·비동기 큐 공용 |
| **API 문서** | `zircote/swagger-php`(v5) | OpenAPI 어트리뷰트 → **RapiDoc** UI (`/api/docs`, 좌측 API 목록) |
| **정적 분석** | PHPStan (level 8) | `src/` 전체 |
| **코드 스타일** | PHP-CS-Fixer (PSR-12) | 커밋 전 자동 정렬 |
| **테스트** | PHPUnit | 단위·통합 |

> 인증 설계 원칙(Access/Refresh 토큰 분리·회전·알고리즘 고정 등)은 전역 [`~/.claude/rules/security.md`](~/.claude/rules/security.md) 참고.

## 로컬 환경 설정

```bash
cp .env.example .env      # .env 복사 후 아래 필수 키 설정
composer install
php bin/console migrate   # 마이그레이션 실행 (phinx 래퍼)
```

`.env` 필수 키:

```env
# 앱
APP_ENV = local
APP_BASE_URL = http://localhost:9300/

# DB
DB_HOST = localhost
DB_NAME = aitessera
DB_USER = aitessera
DB_PASS =

# Redis
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379

# JWT (필수 — 32자 이상 랜덤 문자열, HS256 기준)
JWT_SECRET = E39fksd4fsl90Adfk27fjdkAedkdfh
JWT_ACCESS_TTL  = 900       # 초 단위 (15분)
JWT_REFRESH_TTL = 1209600   # 초 단위 (14일)
```

> 시크릿은 **절대 코드·저장소에 하드코딩 금지**. 운영 환경은 AWS SSM Parameter Store /
> Secrets Manager 를 사용하고 `.env` 는 커밋하지 않는다.

## 커맨드

```bash
composer serve                # 개발 서버 — PHP 내장 서버 (php -S localhost:9300 -t public)
composer test                 # PHPUnit 단독 실행
composer analyse              # PHPStan 단독 실행
composer cs-fix               # PHP-CS-Fixer 자동 정렬
composer check                # cs-check + PHPStan + PHPUnit 순차 실행
php bin/console <command>      # CLI 진입점 (마이그레이션·큐 컨슈머 등)
```

## 디렉토리 규칙

프레임워크가 없으므로 구조를 직접 규정한다. **프론트 컨트롤러(`public/index.php`)** 하나만
웹에 노출하고 나머지는 `src/` 아래에 둔다.

| 경로 | 용도 |
|------|------|
| `public/index.php` | 유일한 진입점(front controller) · 웹 서버 DocumentRoot |
| `src/Controller/` | HTTP 요청 처리 — 얇게 유지 |
| `src/Service/` | 비즈니스 로직 · 트랜잭션 관리 |
| `src/Repository/` | PDO 기반 데이터 접근 계층 |
| `src/Middleware/` | PSR-15 미들웨어 (`JwtAuthMiddleware` 등) |
| `src/Domain/` | DTO(`readonly`) · Enum · 도메인 모델 |
| `src/Exception/` | 도메인 예외 |
| `src/Support/` | 공용 유틸 (JWT·DB 연결·큐·메일·응답 등) |
| `src/Console/` | 큐 컨슈머 등 CLI 워커 (`ProcessMailQueue`·`ProcessLogQueue`) |
| `src/OpenApi/Schema/` | OpenAPI 재사용 스키마 컴포넌트(문서 전용 클래스) |
| `config/` | 컨테이너 정의 · 라우트 정의 · 설정 로딩 |
| `migrations/` | phinx 마이그레이션 |
| `bin/console` | CLI 진입점 (`migrate`·`seed:run`·`mail:work`·`log:work`) |
| `var/` | 런타임 산출물 (로그·캐시) — git 미추적 |
| `docs/` | 프로젝트 문서 |
| `legacy-source/` | 레거시소스 참고용. (깃 추가 금지) |

## 아키텍처 핵심 패턴

### 요청 처리 흐름

```
public/index.php
  → .env 로딩 → DI 컨테이너 빌드
  → PSR-15 미들웨어 파이프라인 (Relay)
      [ErrorHandler → TrailingSlash → Cors → RateLimit → JwtAuth → RouteDispatch]
  → Controller → Service → Repository(PDO)
  → PSR-7 Response 반환
```

> `TrailingSlashMiddleware` 가 끝 슬래시를 정규화한다(`/api/docs/` → `/api/docs`). 공개경로·
> 라우팅이 정확 매칭이므로, 이 정규화가 없으면 끝 슬래시에서 401/404 가 난다.

### JWT 인증 흐름

`JwtAuthMiddleware`(PSR-15)가 `Authorization: Bearer` 토큰을 `lcobucci/jwt` 로 검증한 뒤,
사용자 ID 를 요청 컨텍스트에 실어 다음 미들웨어로 넘긴다. 컨트롤러는 요청 애트리뷰트에서
꺼내 쓴다. 별도 전역 상태 없이 **요청 스코프 안에서만** 유효하다.

```php
// JwtAuthMiddleware → 검증 후 요청 애트리뷰트에 주입
$request = $request->withAttribute('userId', (int) $claims->get('sub'));
return $handler->handle($request);

// Controller 에서 사용
$userId = (int) $request->getAttribute('userId');
```

> JWT 검증 제약(`SignedWith`·`StrictValidAt`·알고리즘 고정)은 인증 우회 방지의 핵심이다 — 전역 [`~/.claude/rules/security.md`](~/.claude/rules/security.md) 참고.

### 프레임워크리스 아키텍처 절대 금지

전역 [`~/.claude/rules/code-style.md`](~/.claude/rules/code-style.md) 의 레이어 책임에 더해, 프레임워크가 없으므로 아래를 특히 강제한다.

| 금지 | 이유 |
|------|------|
| Controller·Service 에서 SQL 직접 실행 | 데이터 접근은 Repository 로 격리 |
| `PDO`·`Redis` 를 전역/싱글턴으로 직접 참조 | DI 컨테이너 경유(생성자 주입) |
| `new Service()` 직접 인스턴스화 | 컨테이너가 주입 — 수동 `new` 금지 |
| 미들웨어 밖에서 인증 상태 조작 | 인증은 `JwtAuthMiddleware` 한 곳으로 |
| `public/` 밖 파일을 웹에 노출 | 진입점은 `public/index.php` 하나만 |

### OpenAPI 문서 (구성 위치)

전역 [`~/.claude/rules/api-design.md`](~/.claude/rules/api-design.md) 의 OpenAPI 규칙을 따르되, AITessera 는:
- **요청/응답 스키마**는 `src/OpenApi/Schema/` 의 `#[OA\Schema]` 컴포넌트로 정의하고 `$ref` 로 참조(인라인 중복 금지).
- 모든 4xx/5xx 응답은 공용 `ErrorResponse` 컴포넌트를 참조.
- 전역 정의(Info·Server·`bearerAuth` 보안 스킴)는 `src/OpenApiSpec.php` 에 둔다.
- 스펙 경로: `/api/v1/openapi.json`(swagger-php v5 가 `src/` 스캔 → JSON, 운영은 캐시).

## CI (GitHub Actions)

`dev` · `main` 으로의 **push / PR** 마다 자동 검증된다. 정의: `.github/workflows/ci.yml`.

- **동시성**: 같은 ref 새 푸시 시 진행 중 실행 취소 (`concurrency.cancel-in-progress`)

### `backend` 잡 — PHP · PHP-CS-Fixer · PHPStan · PHPUnit

`mysql:8.0` · `redis:7` 서비스 컨테이너를 띄우고 다음 순서로 검증한다.

1. setup-php `8.4` (확장: `mbstring intl pdo_mysql redis curl dom xml tokenizer`, 커버리지 `pcov`)
2. Composer 캐시 → `composer install`
3. `.env.example` → `.env` 복사 후 CI용 DB·Redis·`JWT_SECRET` 주입
4. `var/` 하위 디렉토리 생성 (git 미추적, 런타임 경로 보장)
5. `composer cs-check` (PHP-CS-Fixer dry-run)
6. `composer analyse` (PHPStan level 8)
7. MySQL 헬스 대기 → `php bin/console migrate` 로 테스트 스키마 구성
8. `composer test` (PHPUnit 단위·DB 통합)

> 새 PHP 코드는 PHPStan level 8 통과 + 관련 PHPUnit 테스트가 그린이어야 CI를 통과한다. 새 기능에는 `tests/` 테스트를 함께 작성한다.

## CD (배포)

`main` push(= `dev → main` PR 머지) 시 프로덕션 서버로 **SSH 자동 배포**된다. 정의: `.github/workflows/deploy.yml`.

- **트리거**: `main` push + `workflow_dispatch`(수동·롤백)
- **동시성**: `deploy-production` 그룹 — 배포 동시 실행 1개, `cancel-in-progress: false`
- **대상**: Ubuntu + mod_php(또는 PHP-FPM) 아파치 단일 서버 (appleboy/ssh-action)

### 배포 절차 (서버 SSH 실행)

1. `git reset --hard origin/main` — 최신 main 반영
2. `var/` 디렉토리 생성 — **반드시 composer/migrate 이전** (런타임 로그·캐시 경로 보장)
3. `composer install --no-dev --optimize-autoloader`
4. `php bin/console migrate` — 출력을 grep 검사해 예외 감지 시 `exit 1` 로 배포 중단
5. 캐시 초기화 (OPcache / Redis 애플리케이션 캐시)
6. `sudo -n systemctl reload apache2`(또는 `php-fpm`) — OPcache 갱신(무중단)

> **마이그레이션 함정**: DB 연결 실패·마이그레이션 예외가 나도 CLI 종료코드가 0 일 수 있다.
> `set -e` 로 못 잡을 수 있으니 출력을 캡처해 예외 패턴(`[...Exception]`·`Unable to connect`·`Access denied`)을 직접 검사하고 실패 시 배포를 중단한다.

> **var/ chmod 함정**: 런타임에 아파치(`www-data`)가 만든 `var/cache`·`var/logs` 파일은
> 배포 계정 소유가 아니라 `chmod -R 775 var` 가 `Operation not permitted` 로 실패한다.
> 이 `chmod` 는 best-effort(`2>/dev/null || echo …`)로 처리하고, 근본 해결은 아래 서버 준비의 setgid 구성이다.

### 필요한 GitHub Secrets (`production` 환경)

`DEPLOY_HOST` · `DEPLOY_USER` · `DEPLOY_SSH_KEY` · `DEPLOY_PORT` · `DEPLOY_PATH`

### 서버 사전 준비 (한 번만)

- **GitHub 읽기전용 deploy key** — 서버 저장소 리모트를 SSH(`git@github.com:...`)로 설정 (HTTPS면 `could not read Username` 실패)
- **프로덕션 `.env`** 에 실제 DB·Redis·JWT 접속정보 (없으면 migrate 시 `Access denied`)
- **비밀번호 없는 sudo**: `/etc/sudoers.d/aitessera-deploy` 에 `<DEPLOY_USER> ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload apache2` (없으면 `sudo: a password is required` 로 실패)
- 아파치 `DocumentRoot` 는 `public/`, `var/` 는 아파치 유저(`www-data`) 쓰기 가능
- **var/ setgid 구성(권장)** — 소유권 충돌로 인한 chmod 실패를 근본 제거:
  ```bash
  sudo chown -R <DEPLOY_USER>:www-data <DEPLOY_PATH>/var
  sudo chmod -R 2775 <DEPLOY_PATH>/var   # setgid: 새 파일이 www-data 그룹 상속
  ```

### 배포 후 — 기본 관리자 계정 (최초 1회)

배포에는 마이그레이션만 포함되고 **시더는 자동 실행되지 않는다.** 관리자 계정이 없으면 서버에서 한 번 실행한다(재실행 안전).

```bash
cd <DEPLOY_PATH> && php bin/console seed:admin
```

## 클라우드·인프라 (참고)

- **AWS 기본 스택**: ECS(Fargate) + RDS + ElastiCache(Redis) + SQS
- **시크릿 관리**: `.env` 커밋 금지 — AWS SSM Parameter Store / Secrets Manager 사용
- **로그**: 구조화 로그(JSON) 지향
- **헬스체크**: `GET /health` 엔드포인트 (DB·캐시 연결 상태 포함) 제공 권장
