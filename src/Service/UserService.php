<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\EmailVerification;
use App\Domain\Request\CreateOperatorRequest;
use App\Domain\Request\RegisterRequest;
use App\Domain\User;
use App\Domain\UserProfile;
use App\Domain\UserRole;
use App\Exception\AlreadyExistsException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidTokenException;
use App\Exception\NotFoundException;
use App\Exception\TokenExpiredException;
use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\Config;
use App\Support\ConnectionInterface;
use App\Support\Queue\QueueInterface;
use Psr\Clock\ClockInterface;

/**
 * 사용자 유스케이스 — 회원가입·이메일 인증·인증메일 재발송.
 *
 * 가입 시 사용자는 이메일 미인증 상태로 생성되며, 인증 메일 발송은 큐에 위임한다(비동기).
 * 실제 발송은 큐 컨슈머(ProcessMailQueue)가 처리한다.
 */
final readonly class UserService
{
    public const string MAIL_QUEUE = 'mail_queue';

    public function __construct(
        private UserRepositoryInterface $users,
        private EmailVerificationRepositoryInterface $verifications,
        private QueueInterface $queue,
        private Config $config,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {
    }

    /**
     * 현재 사용자의 프로필(민감정보 제외)을 조회한다.
     */
    public function me(int $userId): UserProfile
    {
        $row = $this->users->findProfileById($userId);
        if ($row === null) {
            throw new NotFoundException('사용자를 찾을 수 없습니다.');
        }

        return UserProfile::fromRow($row);
    }

    /**
     * 회원가입 — 사용자 생성 + 인증 토큰 발급(트랜잭션) 후 인증 메일을 큐에 적재한다.
     *
     * @return int 생성된 사용자 id
     */
    public function register(RegisterRequest $request): int
    {
        if ($this->users->emailExists($request->email)) {
            throw new AlreadyExistsException('이미 가입된 이메일입니다.');
        }

        $now = $this->clock->now();
        $token = $this->generateToken();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->emailVerifyTtl));

        $userId = $this->db->transaction(function () use ($request, $now, $token, $expiresAt): int {
            $newId = $this->users->create(
                email: $request->email,
                passwordHash: password_hash($request->password, PASSWORD_ARGON2ID),
                affiliation: $request->affiliation->value,
                name: $request->name,
                contact: $request->contact,
                company: $request->company,
                profile: $request->profile,
                agreedAt: $now,
                role: UserRole::Member, // 자가가입은 항상 일반회원
            );
            $this->verifications->store($newId, $this->hash($token), $expiresAt);

            return $newId;
        });

        $this->enqueueVerificationEmail($request->email, $token);

        return $userId;
    }

    /**
     * 운영자에 의한 계정 생성 — 운영자·대행사 계정을 만든다(이슈 #29).
     *
     * 운영자는 자기 소속(affiliation)과 동일한 계정만 생성할 수 있다. 신뢰 채널로 생성하는
     * 계정이므로 이메일 인증을 즉시 완료 처리하고 인증 메일은 발송하지 않는다.
     *
     * @param int $creatorUserId 생성을 요청한 운영자의 사용자 id
     *
     * @return int 생성된 사용자 id
     */
    public function createOperatorAccount(CreateOperatorRequest $request, int $creatorUserId): int
    {
        $creatorRow = $this->users->findById($creatorUserId);
        if ($creatorRow === null) {
            throw new NotFoundException('생성 요청자를 찾을 수 없습니다.');
        }

        // 정지된 계정은 토큰이 아직 유효해도(발급 후 ≤15분) 신규 계정을 만들 수 없다.
        // findById 는 is_active 를 보지 않으므로 여기서 명시적으로 막는다.
        $creator = User::fromRow($creatorRow);
        if (!$creator->isActive) {
            throw new ForbiddenException('비활성화된 계정은 이 작업을 수행할 수 없습니다.');
        }

        // 소속 스코핑: 운영자는 자기 소속의 계정만 생성 가능(소속 간 권한 경계).
        if ($creator->affiliation !== $request->affiliation) {
            throw new ForbiddenException('본인 소속의 계정만 생성할 수 있습니다.');
        }

        if ($this->users->emailExists($request->email)) {
            throw new AlreadyExistsException('이미 가입된 이메일입니다.');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function () use ($request, $now): int {
            $newId = $this->users->create(
                email: $request->email,
                passwordHash: password_hash($request->password, PASSWORD_ARGON2ID),
                affiliation: $request->affiliation->value,
                name: $request->name,
                contact: $request->contact,
                company: $request->company,
                profile: $request->profile,
                agreedAt: $now,
                role: $request->role,
            );
            // 운영자가 신뢰 채널로 생성 → 이메일 인증 즉시 완료
            $this->users->markEmailVerified($newId, $now);

            return $newId;
        });
    }

    /**
     * 이메일 인증 — 토큰을 확인하고 사용자를 인증 완료 처리한다(트랜잭션).
     */
    public function verifyEmail(string $token): void
    {
        $row = $this->verifications->findByHash($this->hash($token));
        if ($row === null) {
            throw new InvalidTokenException();
        }

        $verification = EmailVerification::fromRow($row);
        if ($verification->isConsumed()) {
            throw new InvalidTokenException('이미 사용된 인증 토큰입니다.');
        }

        $now = $this->clock->now();
        if ($verification->isExpired($now)) {
            throw new TokenExpiredException('인증 토큰이 만료되었습니다.');
        }

        $this->db->transaction(function () use ($verification, $now): void {
            $this->users->markEmailVerified($verification->userId, $now);
            $this->verifications->consume($verification->id, $now);
        });
    }

    /**
     * 인증 메일 재발송 — 미인증 활성 사용자에 한해 이전 토큰을 무효화하고 새로 발급한다.
     *
     * 이메일 존재 여부를 노출하지 않도록, 대상이 없거나 이미 인증된 경우에도 조용히 성공 처리한다.
     */
    public function resendVerification(string $email): void
    {
        $row = $this->users->findActiveByEmail($email);
        if ($row === null) {
            return;
        }

        $user = User::fromRow($row);
        if ($user->isEmailVerified()) {
            return;
        }

        $now = $this->clock->now();
        $token = $this->generateToken();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->config->emailVerifyTtl));

        $this->db->transaction(function () use ($user, $token, $expiresAt): void {
            $this->verifications->deleteUnconsumedForUser($user->id);
            $this->verifications->store($user->id, $this->hash($token), $expiresAt);
        });

        $this->enqueueVerificationEmail($user->email, $token);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function enqueueVerificationEmail(string $email, string $token): void
    {
        $this->queue->push(self::MAIL_QUEUE, [
            'type' => 'email_verification',
            'to' => $email,
            'token' => $token,
        ]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
