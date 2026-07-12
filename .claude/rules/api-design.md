# API 설계 규칙

> [CLAUDE.md](../../CLAUDE.md) 에서 `@import` 로 자동 로딩되는 API 설계 전용 규칙 문서.

## API 응답 포맷

응답 조립은 공용 `JsonResponder`(또는 컨트롤러 베이스 트레이트)로 통일한다.

```php
// 성공
$this->success($data, $meta);
// → { "status": "success", "data": {...}, "meta": {...} }

// 실패
$this->error('ERROR_CODE', '메시지', $statusCode);
// → { "status": "error", "code": "...", "message": "..." }
```

### 페이지네이션 meta 표준

목록 API의 `meta`는 아래 4개 필드를 항상 포함한다.

```php
$this->success($items, [
    'page'      => (int) $page,
    'per_page'  => (int) $limit,
    'total'     => (int) $total,
    'last_page' => (int) ceil($total / $limit),
]);
```

### 에러 코드 네이밍 규칙

`UPPER_SNAKE_CASE` · `도메인_동사` 형식으로 통일한다.

| 에러 코드 | 용도 |
|-----------|------|
| `UNAUTHORIZED` | 인증 토큰 없음 |
| `TOKEN_EXPIRED` | 토큰 만료 |
| `INVALID_TOKEN` | 토큰 형식·서명 오류 |
| `INVALID_CREDENTIALS` | 이메일·비밀번호 불일치 |
| `VALIDATION_ERROR` | 유효성 검사 실패 |
| `NOT_FOUND` | 리소스 없음 |
| `ALREADY_EXISTS` | 중복 리소스 |
| `FORBIDDEN` | 권한 없음 |
| `INTERNAL_ERROR` | 서버 내부 오류 |

### REST URI 설계

- URI는 **복수 명사**: `/api/v1/users`, `/api/v1/tokens`
- 버전 prefix 필수: `/api/v1/`
- URI에 **동사 금지**: `/getUser` ❌ → `GET /users/{id}` ✅
- 필터·정렬·페이지는 쿼리스트링: `?filter[status]=active&sort=-created_at&page=1&per_page=20`

### HTTP 상태코드

| 상황 | 코드 |
|------|------|
| 조회 성공 | 200 |
| 생성 성공 | 201 |
| 처리 성공 (응답 본문 없음) | 204 |
| 인증 실패 | 401 |
| 권한 없음 | 403 |
| 리소스 없음 | 404 |
| 유효성 검사 실패 | 422 |
| 서버 오류 | 500 |

## OpenAPI(Swagger) 문서 규칙

새 API 엔드포인트마다 PHP 어트리뷰트 추가 필수. 문서 UI 는 `/api/docs`(RapiDoc, 좌측 API
목록), 스펙은 `/api/v1/openapi.json`(swagger-php v5 가 `src/` 스캔 → JSON, 운영은 캐시).

```php
use OpenApi\Attributes as OA;

// 요청/응답은 재사용 스키마 컴포넌트를 $ref 로 참조한다.
#[OA\Post(
    path: '/api/v1/tokens',
    summary: '로그인 — 토큰 발급',
    security: [],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')),
    tags: ['Auth'],
    responses: [
        new OA\Response(response: 201, description: '토큰 발급', content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')),
        new OA\Response(response: 401, description: '자격증명 불일치', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
public function issue(ServerRequestInterface $request): ResponseInterface { ... }
```

- **요청/응답 스키마는 `src/OpenApi/Schema/` 의 `#[OA\Schema]` 컴포넌트로 정의**하고 `$ref` 로 참조한다(인라인 중복 금지).
- 모든 4xx/5xx 응답은 공용 `ErrorResponse` 컴포넌트를 참조한다.
- 전역 정의(Info·Server·`bearerAuth` 보안 스킴)는 `src/OpenApiSpec.php` 에 둔다.
