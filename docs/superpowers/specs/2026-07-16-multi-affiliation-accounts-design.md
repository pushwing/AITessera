# 동일 이메일 다중 소속(제품군) 가입 지원 설계

> 작성일: 2026-07-16

## 배경

`users` 테이블은 현재 `email`이 (활성 행 기준) 전역 유니크라, 한 사람이 서로 다른 제품군
(`affiliation`: aicura|aicopia|aicreo|aivance|ailicet)에 같은 이메일로 가입하면 두 번째
가입 시도가 `ALREADY_EXISTS`로 거절된다. 운영 요구사항은 **한 이메일로 여러 제품군에 각각
가입**할 수 있어야 한다는 것.

## 설계 결정 (승인됨)

1. **계정 모델**: 제품군별 **완전 독립 계정**. 같은 이메일이어도 소속마다 별도의 `users` 행
   (별도 비밀번호·이름·연락처·role)을 가진다. "한 사람의 통합 계정 + 소속별 role" 모델은
   채택하지 않는다.
2. **유니크 제약**: DB 레벨 **복합 유니크 인덱스** `(email_active, affiliation)`. 애플리케이션
   레벨 체크만으로는 동시 가입 요청 시 경합 조건(race condition)으로 중복이 생길 수 있어 제외.
3. **로그인 구분 기준**: 로그인 요청에 `affiliation`을 **필수 파라미터로 명시**. email+password만으로
   자동 판별(비밀번호 우연 일치 시 모호해짐)하는 방식은 채택하지 않는다.
4. **하위 호환**: fallback 없음. API 변경과 각 제품군 프론트엔드 배포를 **동시에** 진행한다.

## 컴포넌트

### DB 스키마

기존 `20260707100000_scope_users_email_unique_to_active.php`가 도입한 생성 컬럼
`email_active`(탈퇴 시 NULL)를 재사용하고, 유니크 인덱스만 확장하는 신규 마이그레이션을 추가한다.

```sql
ALTER TABLE users DROP INDEX uniq_users_email_active;
ALTER TABLE users ADD UNIQUE INDEX uniq_users_email_active_affiliation (email_active, affiliation);
```

기존 데이터는 이미 소속당 이메일이 유일한 상태이므로 무중단 적용 가능(백필 불필요). `down()`은
직전 마이그레이션과 동일하게 "재가입 발생 후 롤백 불가" 제약을 문서화한다.

### API 변경

| 엔드포인트 | 변경 내용 |
|---|---|
| `POST /api/v1/tokens` (로그인) | `LoginRequest`에 `affiliation` 필드 추가(필수, `Affiliation` enum 검증) |
| `POST /api/v1/users` (자가가입) | 기존에 이미 `affiliation`을 받음 — 중복 체크 스코프만 변경 |
| `POST /api/v1/operators` (운영자에 의한 계정 생성) | 중복 체크 스코프만 변경(운영자는 이미 자기 소속으로만 생성 가능) |
| `POST /api/v1/users/verify/resend` (인증메일 재발송) | `affiliation` 필수 파라미터 추가(같은 이메일이 여러 소속에 미인증 상태로 있을 수 있어 모호성 제거) |

### 서비스 · 리포지토리 레이어

`UserRepositoryInterface`의 시그니처 변경:

- `findActiveByEmail(string $email)` → `findActiveByEmail(string $email, string $affiliation)`
- `emailExists(string $email)` → `emailExists(string $email, string $affiliation)`

호출부 4곳 수정: `AuthService::login()`, `UserService::register()`,
`UserService::createOperatorAccount()`, `UserService::resendVerification()`.

`UserAdminService`의 목록/상세 조회(`paginateByAffiliation`, `findManageableById` 등)는 이미
affiliation 스코프이므로 변경 불필요.

### OpenAPI 문서

- `src/OpenApi/Schema/LoginRequest.php`에 `affiliation` 프로퍼티 추가(`required`에 포함)
- 인증메일 재발송 요청 스키마에도 `affiliation` 추가
- 로그인 엔드포인트의 422(`VALIDATION_ERROR`) 응답은 기존 컴포넌트 재사용

## 에러 처리

- `affiliation` 누락/미정의 값 → 기존 `ValidationException` 경로(422 `VALIDATION_ERROR`), 신규 처리 불필요
- 이메일은 존재하지만 해당 `affiliation`엔 없는 계정으로 로그인 시도 → 기존과 동일하게
  `InvalidCredentialsException`(401 `INVALID_CREDENTIALS`) — 계정 존재 여부를 노출하지 않는
  기존 보안 원칙 유지
- 같은 (email, affiliation) 조합 중복 가입 → 기존과 동일하게 `AlreadyExistsException`(409)

## 테스트 (PHPStan L8 + PHPUnit)

- `tests/Feature/`:
  - 같은 이메일로 서로 다른 두 소속에 각각 가입 → 둘 다 성공
  - 같은 (email, affiliation) 조합 중복 가입 → `ALREADY_EXISTS`
  - 가입하지 않은 소속으로 로그인 시도(이메일은 다른 소속에 존재) → `INVALID_CREDENTIALS`
  - `affiliation` 없이 로그인 요청 → `VALIDATION_ERROR`(422)
  - 기존 로그인/가입/인증재발송 관련 테스트 픽스처에 `affiliation` 필드 추가
- `tests/Unit/`: `LoginRequest::fromArray()`에 `affiliation` 필수 검증 케이스 추가

## 영향받지 않는 부분

- JWT 발급 구조(`AuthService::issuePair()`) — 로그인 시점에 정확히 한 행(email+affiliation 매칭)이
  확정되므로 토큰에 실리는 affiliation·role은 기존과 동일하게 해당 행 기준 그대로.
- `UserAdminService`의 운영자용 회원 목록/상세/수정 — 이미 소속 스코프라 교차 노출 없음.
