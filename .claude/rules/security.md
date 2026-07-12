# 보안 규칙

> [CLAUDE.md](../../CLAUDE.md) 에서 `@import` 로 자동 로딩되는 보안 전용 규칙 문서.

## 인증 설계 원칙

- **Access Token(단기, 15분) + Refresh Token(장기)** 분리
- Refresh Token 은 Redis/DB 에 저장해 **회전(rotation)** 과 **무효화(블랙리스트)** 관리 — Stateless JWT 의 로그아웃 문제 해결
- 알고리즘: 단일 서버는 **HS256**, 서비스 간 검증 분산이면 **RS256(비대칭키)**
- 비밀번호는 `password_hash()` (Argon2id 권장)
- `alg: none` 공격 방어 위해 검증 시 알고리즘을 명시적으로 고정

## JWT 검증 제약

> 검증 시 `SignedWith` + `StrictValidAt` + 알고리즘 고정 제약을 반드시 건다.
> 서명·만료·`alg` 를 하나라도 검증에서 빠뜨리면 인증 우회로 이어진다.

## 시크릿 관리

시크릿은 **절대 코드·저장소에 하드코딩 금지**. 운영 환경은 AWS SSM Parameter Store /
Secrets Manager 를 사용하고 `.env` 는 커밋하지 않는다.

## PHP 절대 금지 — 보안

| 금지 | 이유 | 대신 |
|------|------|------|
| `$_GET`·`$_POST`·`$_REQUEST` 직접 사용 | 필터링 없는 원시 입력 | PSR-7 `$request->getQueryParams()` / `getParsedBody()` + 검증 |
| SQL 문자열 직접 조합 | SQL Injection | PDO prepared statement 바인딩 |
| 사용자 입력 그대로 출력 | XSS | JSON 인코딩 / `htmlspecialchars()` |
| `eval()` 사용 | 코드 인젝션 | 사용 이유 자체를 제거 |
| `md5()` / `sha1()`로 비밀번호 저장 | 취약한 해시 | `password_hash()` (Argon2id) |
| JWT·서명 직접 구현 | 미묘한 암호 결함 | `lcobucci/jwt` 등 검증된 라이브러리 |
| JWT 검증에서 `alg` 미고정 | `alg:none` 우회 공격 | 허용 알고리즘 명시적 고정 |
| 시크릿·API키 코드에 하드코딩 | 노출 위험 | `.env` + 설정 객체 |
| 업로드 파일 검증 없이 저장 | 악성 파일 업로드 | 확장자·MIME 검증 필수 |
| 운영에서 스택 트레이스 노출 | 내부 구조 노출 | `APP_ENV=production` 시 상세 에러 숨김 |
