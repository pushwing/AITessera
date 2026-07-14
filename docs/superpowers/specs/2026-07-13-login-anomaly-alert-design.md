# 로그인 이상 탐지 → 리포트/메일 발송 설계

> 작성일: 2026-07-13 · 관련: #52(로그인 이상 탐지) 후속, Epic #49

## 배경

`security:scan`(`ProcessLoginAnomaly`) 워커가 로그인 이벤트를 스코어링해 `login_events`
테이블과 `var/logs/security/*.log` 에 기록하지만, **메일 알림 경로가 없어** 운영자가 능동적으로
파일/DB 를 봐야 이상을 인지할 수 있다. 이를 메일로 밀어주는 두 경로를 추가한다.

요청 사이클은 건드리지 않고, 기존 비동기 큐/스케줄러 단계에만 얹는다(부하 분산 원칙 유지).

## 설계 결정 (승인됨)

1. **발송 방식**: 실시간 임계값 초과 알림 + 일일 요약 리포트 **둘 다**
2. **실시간 폭주 제어**: 계정+IP별 **쿨다운**(Redis TTL) — brute-force 시 메일 폭주 방지
3. **일일 요약 구조**: 기존 `report:daily` 와 분리한 **별도 `report:security` 커맨드**
4. **수신자**: 전용 `SECURITY_ALERT_RECIPIENT`(미설정 시 `LOG_REPORT_RECIPIENT` 폴백)
5. **본문 생성**: 일일 요약은 **AI 서술 + 통계 폴백**(기존 LogReportWriter 패턴), 실시간 알림은
   `anomaly_reason` 이 이미 계산돼 있으므로 **AI 없이 구조화 템플릿**

## 컴포넌트

### 실시간 알림 (기존 `ProcessLoginAnomaly` 확장)
- 임계값 초과 시 anomaly 로그 append 에 더해, **쿨다운 통과 시에만** `login_anomaly_alert`
  메일 잡을 큐에 적재
- 쿨다운: 신규 `CooldownInterface` + `RedisCooldown`(predis `SET NX EX`) / `InMemoryCooldown`(테스트).
  키 `login_anomaly:{sha1(email|ip)}`, TTL `ANOMALY_ALERT_COOLDOWN`(기본 1800초). 이미 존재하면 스킵
- 쿨다운 저장소 조회 실패 시 **fail-open**(알림 누락 방지 우선)
- 수신자 미설정이면 스킵

### 일일 보안 리포트 (신규 `GenerateSecurityReport` + `report:security`)
- `LoginEventRepository::aggregateForDate(date, threshold)` → `DailySecurityStats` DTO
  (총 시도·실패·이상 건수·최고점수·상위 계정/IP). prepared statement 만 사용
- `SecurityReportWriterInterface`(`ClaudeSecurityReportWriter`/`NullSecurityReportWriter`)
  → 자연어 요약, 실패 시 통계 폴백
- `daily_security_report` 메일 잡 적재

### 메일 발송 (기존 `ProcessMailQueue` 확장)
- `match($type)` 에 `login_anomaly_alert`·`daily_security_report` 케이스 추가 →
  공통 `sendReportEmail()`(to/subject/body → `mailer->sendReport`) 재사용

## 설정 · 데이터

- **Config**: `SECURITY_ALERT_RECIPIENT`(폴백 `LOG_REPORT_RECIPIENT`), `ANOMALY_ALERT_COOLDOWN`(1800)
  + `resolvedSecurityRecipient()` 헬퍼
- **마이그레이션**: `login_events.occurred_at` 단독 인덱스 추가(일일 집계 범위 WHERE 대상)
- **DTO**: `src/Domain/Security/DailySecurityStats.php`(`final readonly`)
- **컨테이너 바인딩**: `CooldownInterface`, `SecurityReportWriterInterface`(API 키 유무 factory 분기)
- **bin/console**: `report:security` 커맨드 등록

## 에러 처리

- 스코어링·발송 실패 → 기존 dead-letter(`var/logs/queue-failed/`)
- AI 미가용·타임아웃 → 통계 폴백(요약) / 알림은 AI 미사용이라 무영향
- 수신자 미설정 → 조용히 스킵(기존 `report:daily` 와 동일)

## 테스트 (PHPStan L8 + PHPUnit)

- `ProcessLoginAnomaly`: 임계값 초과 시 알림 적재 / 쿨다운 2회차 억제 / 수신자 없으면 미적재
- `InMemoryCooldown` 단위, `SecurityReportWriter` 폴백 단위
- `GenerateSecurityReport`: 리포트 생성·폴백·수신자 스킵
- `LoginEventRepository::aggregateForDate` 통합(DB)
- HTTP 엔드포인트 없음 → OpenAPI 변경 없음. README·.env.example 갱신
