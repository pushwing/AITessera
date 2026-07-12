# 테스트 · 정적 분석 규칙

> [CLAUDE.md](../../CLAUDE.md) 에서 `@import` 로 자동 로딩되는 테스트·정적 분석 전용 규칙 문서.

## 정적 분석 (PHPStan)

코드 작성 후 반드시 정적 분석을 통과해야 한다.

```bash
composer analyse          # PHPStan 단독 실행
composer check            # cs-check + PHPStan + PHPUnit 순차 실행
```

- 분석 레벨: **8** (`phpstan.neon`)
- 분석 대상: `src/`
- 새 클래스·메서드 작성 시 `array<string, mixed>` 등 제네릭 타입 명시 필수
- `@phpstan-ignore` 주석으로 억제 금지 — 원인을 찾아 코드를 수정할 것

## 테스트

```bash
composer test                 # PHPUnit 단독 실행
```

- 단위 테스트: `tests/Unit/` — 외부 의존성 Mock
- 통합 테스트: `tests/Feature/` — 실제 DB(테스트 스키마) + 트랜잭션 롤백
- 커버리지 목표: **Service 레이어 80% 이상**
- 테스트 DB는 `.env.testing` 별도 설정 — 운영 DB 절대 사용 금지
- 새 기능 구현 시 테스트 코드를 함께 작성한다
