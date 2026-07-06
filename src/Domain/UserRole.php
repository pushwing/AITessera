<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * 회원구분 — 접근 권한 등급.
 *
 * 소속(Affiliation)이 "어느 서비스 회원인가"라면, 회원구분은 "그 서비스 안에서 어떤 권한인가"를
 * 나타낸다. 운영자 페이지 접근 등 인가(authorization) 판단의 기준값이며, JWT 클레임(`role`)으로
 * 실어 미들웨어에서 검사한다. 자가가입은 항상 일반회원(Member)이며, 운영자·대행사 계정은
 * 운영자만 별도 엔드포인트로 생성한다.
 */
enum UserRole: int
{
    case Operator = 1;   // 운영자
    case Agency = 2;     // 대행사
    case Member = 3;     // 일반회원 (기본값)

    public function label(): string
    {
        return match ($this) {
            self::Operator => '운영자',
            self::Agency => '대행사',
            self::Member => '일반회원',
        };
    }
}
