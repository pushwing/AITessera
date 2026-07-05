<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\RefreshToken;
use App\Domain\Request\LoginRequest;
use App\Domain\TokenPair;
use App\Domain\User;
use App\Exception\EmailNotVerifiedException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidTokenException;
use App\Exception\TokenExpiredException;
use App\Repository\RefreshTokenRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\ConnectionInterface;
use App\Support\JwtIssuer;
use Psr\Clock\ClockInterface;

/**
 * 인증 유스케이스 — 로그인(토큰 발급)·Refresh 회전·로그아웃(무효화).
 *
 * Refresh 토큰은 회전(rotation)한다: 사용될 때마다 이전 토큰을 폐기하고 새로 발급한다.
 * 이미 폐기된 토큰이 다시 제시되면 탈취로 간주하고 해당 사용자의 모든 토큰을 무효화한다.
 */
final readonly class AuthService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private JwtIssuer $jwt,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {
    }

    public function login(LoginRequest $request): TokenPair
    {
        $row = $this->users->findActiveByEmail($request->email);
        if ($row === null) {
            throw new InvalidCredentialsException();
        }

        $user = User::fromRow($row);
        if (!password_verify($request->password, $user->passwordHash)) {
            throw new InvalidCredentialsException();
        }

        if (!$user->isEmailVerified()) {
            throw new EmailNotVerifiedException();
        }

        $pair = $this->issuePair($user->id, $user->affiliation->value);
        $this->users->updateLastLogin($user->id, $this->clock->now());

        return $pair;
    }

    public function refresh(string $refreshToken): TokenPair
    {
        $row = $this->refreshTokens->findByHash($this->jwt->hashRefreshToken($refreshToken));
        if ($row === null) {
            throw new InvalidTokenException();
        }

        $token = RefreshToken::fromRow($row);
        $now = $this->clock->now();

        // 폐기된 토큰 재사용 → 탈취 간주, 사용자 전체 토큰 무효화
        if ($token->isRevoked()) {
            $this->refreshTokens->revokeAllForUser($token->userId, $now);

            throw new InvalidTokenException('재사용이 감지되어 모든 세션이 무효화되었습니다.');
        }

        if ($token->isExpired($now)) {
            throw new TokenExpiredException();
        }

        $userRow = $this->users->findById($token->userId);
        if ($userRow === null) {
            throw new InvalidTokenException();
        }
        $user = User::fromRow($userRow);

        // 회전: 이전 토큰 폐기 + 새 토큰 발급을 하나의 트랜잭션으로
        return $this->db->transaction(function () use ($token, $user, $now): TokenPair {
            $this->refreshTokens->revoke($token->id, $now);

            return $this->issuePair($user->id, $user->affiliation->value);
        });
    }

    public function logout(string $refreshToken): void
    {
        $row = $this->refreshTokens->findByHash($this->jwt->hashRefreshToken($refreshToken));
        if ($row === null) {
            return; // 멱등 — 이미 없는 토큰
        }

        $token = RefreshToken::fromRow($row);
        if (!$token->isRevoked()) {
            $this->refreshTokens->revoke($token->id, $this->clock->now());
        }
    }

    private function issuePair(int $userId, string $affiliation): TokenPair
    {
        $access = $this->jwt->issueAccessToken($userId, $affiliation);
        $refresh = $this->jwt->generateRefreshToken();
        $this->refreshTokens->store($userId, $refresh['hash'], $refresh['expiresAt']);

        return new TokenPair($access, $this->jwt->accessTtl(), $refresh['token']);
    }
}
