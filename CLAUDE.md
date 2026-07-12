# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

AITessera — Secure JWT-based authentication & authorization API for the AIvance service suite.
**프레임워크 없는 순수 모던 PHP(8.3+)** 기반 REST API 단일 프로젝트. 검증된 표준 라이브러리를
조합해 구성하며, JWT·암호화 등 보안 핵심은 절대 직접 구현하지 않고 신뢰받는 라이브러리에 위임한다.

## 규칙 문서 구성

주제별 세부 규칙은 `.claude/rules/` 로 분리했다. 아래 `@import` 로 **자동 로딩**되므로 별도로 열지 않아도 항상 적용된다.

- [`security.md`](.claude/rules/security.md) — 인증 설계·JWT 검증·보안 절대 금지
- [`code-style.md`](.claude/rules/code-style.md) — 네이밍·코딩 규칙·모던 PHP·레이어 책임·도메인 예외
- [`testing.md`](.claude/rules/testing.md) — 정적 분석(PHPStan)·테스트
- [`api-design.md`](.claude/rules/api-design.md) — 응답 포맷·에러 코드·REST URI·OpenAPI

@.claude/rules/security.md
@.claude/rules/code-style.md
@.claude/rules/testing.md
@.claude/rules/api-design.md

## 언어 규칙

- 모든 응답은 반드시 한국어로 작성할 것
- 코드 주석도 한국어로 작성할 것
- 영어 응답은 절대 금지

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

> 인증 설계 원칙(Access/Refresh 토큰 분리·회전·알고리즘 고정 등)은 [`.claude/rules/security.md`](.claude/rules/security.md) 참고.

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

> JWT 검증 제약(`SignedWith`·`StrictValidAt`·알고리즘 고정)은 인증 우회 방지의 핵심이다 — [`.claude/rules/security.md`](.claude/rules/security.md) 참고.

## Git 워크플로우

```
feature/* → (PR) → dev → (PR) → main
```

- **PR 대상**: `feature/*` → `dev`
- **배포**: `dev` → `main` PR
- **머지 방식**:
  - `feature/*` → `dev`: **Squash and merge**
  - `dev` → `main`(배포): **Merge commit** (⚠️ Squash 금지 — 아래 주의)
- **머지 후**: `feature/*` 브랜치 자동 삭제
- `main`에 직접 push 금지
- `dev`에 직접 push 금지 (단, **문서 전용 변경**은 예외 — 아래 참고)

> ⚠️ **`dev → main` 배포 PR 을 Squash 로 머지하면 안 된다.** Squash 는 `dev` 커밋들을
> 새 커밋 하나로 눌러 `main` 을 `dev` 의 조상에서 이탈시킨다. 그러면 다음 배포마다
> `deploy.yml` 등에서 3-way 충돌이 재발한다. **반드시 merge commit** 으로 머지해
> `main` 이 `dev` 의 조상으로 유지되게 한다(배포 = fast-forward → 무충돌).

### 문서 전용 변경 예외

**코드 변경 없이 문서만 수정된 경우**엔 `feature/*` 브랜치·PR 절차를 건너뛰고 `dev` 에 직접 커밋·반영한다.

- **대상 (문서 파일만)**: `*.md`(CLAUDE.md·README 등), `.claude/rules/`, `docs/`
- **예외 아님**: `src/`·`migrations/`·`config/`·`tests/`·`composer.json`·`.github/` 등 **코드·설정이 하나라도 섞이면** 기존 `feature/*` → `dev` PR 흐름을 따른다
- 커밋 메시지는 `docs:` 접두어 사용
- **`main` 직접 push 는 문서라도 여전히 금지** — 배포는 항상 `dev` → `main` PR(merge commit)

### 기능 개발 시작

```bash
git checkout dev
git pull origin dev
git checkout -b feature/기능명   # 예: feature/token-refresh
```

### dev가 앞서간 경우 rebase

```bash
git rebase origin/dev
git push --force-with-lease origin feature/기능명
```

### 커밋 메시지 (Conventional Commits)

| 접두어 | 용도 |
|--------|------|
| `feat` | 새 기능 |
| `fix` | 버그 수정 |
| `refactor` | 리팩토링 |
| `docs` | 문서 |
| `chore` | 설정·빌드 |
| `test` | 테스트 |

자세한 내용: `docs/git-workflow.md`

## API 부하 분산 원칙

API 개발 시 부하 분산을 최우선으로 고려한다. 아래 원칙을 기본으로 적용한다.

### 캐시

- 변경 빈도가 낮은 조회 응답은 **Redis 캐시** 적용
- 캐시 키 규칙: `{리소스}:{식별자}:{파라미터해시}` (예: `users:list:abc123`)
- TTL 기준

| 데이터 성격 | TTL |
|------------|-----|
| 설정·코드성 데이터 | 1시간 이상 |
| 목록·집계 | 5–60분 |
| 단건 상세 | 5–10분 |
| 실시간 필요 데이터 | 캐시 적용 금지 |

- 쓰기(INSERT·UPDATE·DELETE) 발생 시 관련 캐시 즉시 무효화
- 캐시 미스 시 DB 조회 후 캐시 저장 — 로직은 Service 레이어에서 처리

### 큐

- 즉시 응답이 불필요한 작업은 큐로 위임 (이메일·알림·로그·리포트 생성 등)
- API는 큐 적재 후 즉시 `202 Accepted` 반환
- 무거운 연산(배치 집계·엑셀 생성 등)은 절대 요청 사이클 안에서 처리 금지

### DB 쿼리

- `SELECT *` 금지 — 필요한 컬럼만 명시
- N+1 쿼리 금지 — 관계 데이터는 JOIN 으로 조회
- 목록 API는 반드시 페이징 적용 (`limit` / `offset` 또는 커서 기반)
- 인덱스 없는 컬럼 `WHERE` 조건 금지 — 마이그레이션에 인덱스 함께 정의
- 집계 쿼리(`COUNT`, `SUM` 등)는 캐시 우선 적용

### API 응답

- 불필요한 필드 제거 — 응답 페이로드 최소화
- 목록 응답에 `meta.total`, `meta.page` 포함
- 대용량 데이터 응답은 스트리밍 또는 청크 분할 고려

### 기타

- 외부 API 호출은 타임아웃 설정 필수 (기본 5초)
- 외부 API 실패 시 재시도는 큐로 처리 (즉시 재시도 금지)
- 동일 엔드포인트 반복 호출 방어: `symfony/rate-limiter` 미들웨어 적용

## 로그 수집 파이프라인

프론트(앱/웹)에서 API로 전송되는 로그는 큐를 통해 비동기 처리한다.

### 흐름

```
앱/웹
  │
  │ POST /api/v1/logs
  ▼
API Server
  │ 큐에 적재 (즉시 응답)
  ▼
Queue (Redis)
  │
  ▼
Queue Consumer (bin/console 워커 / 스케줄러)
  ├── 원시 로그 → 파일 저장 (var/logs/raw/YYYY-MM-DD.log)
  └── 가공 데이터 → DB INSERT
```

### 규칙

- API는 로그를 받는 즉시 큐에 넣고 `202 Accepted` 응답 — DB 직접 쓰기 금지
- 큐 드라이버: **Redis** (`predis/predis`)
- 원시(raw) 로그는 `var/logs/raw/` 에 날짜별 파일로 append
- 가공 후 DB 저장 — 원시 파일은 보존 (감사·재처리 용도)
- Consumer 는 `bin/console` CLI 워커로 구현, cron / systemd timer 로 주기 실행
- 큐 처리 실패 시 dead-letter 로깅 필수 (`var/logs/queue-failed/`)

### 기본 패턴

```php
// API Controller — 큐에 적재
public function store(ServerRequestInterface $request): ResponseInterface
{
    $payload = (array) $request->getParsedBody();
    // 유효성 검사 후
    $this->redis->lpush('log_queue', json_encode($payload, JSON_THROW_ON_ERROR));
    return $this->respond(null, 202);
}

// bin/console 워커 — Consumer
final class ProcessLogQueue
{
    public function run(): void
    {
        while ($raw = $this->redis->rpop('log_queue')) {
            // 1. 원시 파일 저장
            file_put_contents(
                VAR_PATH . '/logs/raw/' . date('Y-m-d') . '.log',
                $raw . PHP_EOL,
                FILE_APPEND,
            );
            // 2. 가공 후 DB 저장
            $data = $this->transform(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            $this->logRepository->insert($data);
        }
    }
}
```

## PHP 언어 서버 (Intelephense LSP)

Claude Code 가 PHP 코드를 심볼 단위(정의 이동·참조 찾기·자동완성)로 정확히 다루도록 **Intelephense LSP** 를 연동한다. PHPStan 이 "타입 오류 검사"라면 Intelephense 는 "코드 구조 이해" 역할로 상호 보완한다.

> 이 연동은 **Claude Code CLI 세션 전용**이다. VS Code·JetBrains 확장에서 쓰는 Intelephense 와는 별개 인스턴스이므로 에디터에는 에디터대로 따로 설치한다.

### 설치 (최초 1회)

```bash
# 1. 바이너리 설치 (Node.js + npm 필요)
npm install -g intelephense

# 2. 로컬 LSP 플러그인 생성 (~/.claude/skills/ 하위 → 전 프로젝트 공용)
mkdir -p ~/.claude/skills/php-lsp-intelephense/.claude-plugin

cat > ~/.claude/skills/php-lsp-intelephense/.claude-plugin/plugin.json << 'EOF'
{
  "name": "php-lsp-intelephense",
  "description": "Intelephense PHP 언어 서버",
  "version": "1.0.0"
}
EOF

cat > ~/.claude/skills/php-lsp-intelephense/.lsp.json << 'EOF'
{
  "php": {
    "command": "intelephense",
    "args": ["--stdio"],
    "extensionToLanguage": { ".php": "php" }
  }
}
EOF
```

> ⚠️ 공식 `php-lsp@claude-plugins-official` 플러그인은 `.lsp.json` 이 누락되어 동작하지 않는다([이슈 #444](https://github.com/anthropics/claude-plugins-official/issues/444)). 위처럼 로컬 플러그인을 직접 만든다.

### 활성화·확인

- **활성화**: 새 Claude Code 세션을 시작하거나, 대화형 세션에서 `/reload-plugins` 실행 (플러그인은 세션 시작 시 로드된다)
- **확인**: `/help` 의 "Installed plugins" 에 `php-lsp-intelephense` 표시
- **동작 점검**: `intelephense --version` 은 플래그 미지원으로 에러를 뱉으니 정상 판정 근거로 쓰지 말 것. 실제 기동은 `--stdio` 모드의 `initialize` 응답으로 확인한다.

### 사용

개발자가 직접 실행하는 명령이 아니라, Claude 가 PHP 코드를 다룰 때 뒤에서 참조한다. "이 메서드 쓰는 곳 전부 찾아줘", "정의로 가줘" 같은 요청을 텍스트 grep 대신 심볼 단위로 정확히 처리한다.

- **무료 범위**: 정의 이동·참조 찾기·자동완성·심볼 검색 (충분)
- **프리미엄($25/년)**: 워크스페이스 전역 rename·고급 리팩토링

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

### 브랜치 삭제 방지

저장소가 프라이빗+무료 플랜이라 GitHub 브랜치 보호·Ruleset API 는 사용 불가(Pro 필요). 대신 저장소 설정 `delete_branch_on_merge=false` 로 `dev → main` 머지 시 `dev` 자동삭제를 막는다. `main` 은 기본 브랜치라 삭제 불가.

## 클라우드·인프라 (참고)

- **AWS 기본 스택**: ECS(Fargate) + RDS + ElastiCache(Redis) + SQS
- **시크릿 관리**: `.env` 커밋 금지 — AWS SSM Parameter Store / Secrets Manager 사용
- **로그**: 구조화 로그(JSON) 지향
- **헬스체크**: `GET /health` 엔드포인트 (DB·캐시 연결 상태 포함) 제공 권장
