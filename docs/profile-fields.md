# 소속별 회원 프로필 필드

가입 시 공통 필수 항목(`email`·`password`·`name`·`contact`·약관/제3자 동의) 외에,
소속(`affiliation`)별 부가 항목은 `users.profile`(JSON) 컬럼에 저장한다.

검증은 [`App\Domain\ProfileSchema`](../src/Domain/ProfileSchema.php)가 담당하며, 근거는 이슈 #13 이다.

## 검증 규칙

- 각 필드는 **선택(optional)** — 제공된 경우에만 타입을 검증한다.
- 소속에 **정의되지 않은 키**가 오면 거절한다(`VALIDATION_ERROR`, 422).
- 타입이 맞지 않으면 거절한다.

## 소속별 정의

| 소속 | `name` 필드 의미 | `profile` 추가 필드 |
|------|-----------------|---------------------|
| `aicura` | username | `age`(양의 정수), `sex`(문자열), `where_from`(문자열) |
| `aicopia` | 닉네임 | `gender`(문자열), `birthday`(`YYYY-MM-DD`), `zipcode`(문자열), `address1`(문자열), `address2`(문자열) |
| `aicreo` | 닉네임 | 없음 |
| `aivance` | — | 없음 |
| `ailicet` | — | 없음 |

> `name` 필드 의미(username/닉네임)는 프론트엔드 표기 규칙이며, 저장은 공통 `name` 컬럼에 한다.
> `aicreo`·`aivance`·`ailicet` 은 추가 필드가 없으므로 `profile` 에 어떤 키가 와도 거절된다.

## 예시

```jsonc
// aicura 가입 시 profile
{ "age": 30, "sex": "M", "where_from": "Seoul" }

// aicopia 가입 시 profile
{ "gender": "female", "birthday": "1990-05-01", "zipcode": "06236",
  "address1": "서울시 강남구", "address2": "테헤란로 1" }

// aicreo/aivance/ailicet — profile 생략(또는 {})
{}
```

## 향후

- 회원정보 **수정** 엔드포인트 도입 시 동일하게 `ProfileSchema` 로 검증한다.
- 필수 여부·성별 코드값(enum) 등 세부 제약은 각 서비스 요구가 확정되면 강화한다.
