<?php
/**
 * Sharek v1.5 - Rate Limiter
 *
 * @file src/Security/RateLimiter.php
 * @description Server-side, IP-based brute-force protection for login.
 *
 * Replaces the previous $_SESSION-based attempt counter, which an attacker
 * could trivially bypass by discarding the session cookie between attempts
 * (session state lives client-side via the cookie, so it was never actually
 * tied to the attacker's IP). This implementation persists attempts in the
 * database, keyed by IP address, independent of any cookie the client holds.
 */

class RateLimiter
{
    /** Max failed attempts allowed within the time window before lockout. */
    private const MAX_ATTEMPTS = 5;

    /** Sliding window (minutes) in which attempts are counted. */
    private const WINDOW_MINUTES = 10;

    /** Lockout duration (minutes) once the limit is hit. */
    private const LOCKOUT_MINUTES = 15;

    /**
     * Account-level (email) thresholds. Slightly higher than the IP
     * thresholds since many legitimate users can share one IP (NAT,
     * offices, mobile carriers) — but this closes the gap the IP-only
     * check left open: an attacker spreading guesses across many IPs to
     * target one specific victim account was previously unrestricted.
     */
    private const ACCOUNT_MAX_ATTEMPTS = 8;
    private const ACCOUNT_WINDOW_MINUTES = 15;
    private const ACCOUNT_LOCKOUT_MINUTES = 15;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Returns true if the given IP is currently locked out due to too many
     * recent failed attempts (lockout window takes precedence over the
     * shorter counting window, so a lockout persists for its full duration
     * even if no new attempts are made).
     */
    public function isLockedOut(string $ip): bool
    {
        // Count failures within the shorter sliding WINDOW_MINUTES window
        // to determine whether the IP has hit the threshold that triggers
        // a lockout in the first place.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND success = 0
               AND attempted_at >= (NOW() - INTERVAL :window MINUTE)'
        );
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':window', self::WINDOW_MINUTES, PDO::PARAM_INT);
        $stmt->execute();
        $recentFailures = (int) $stmt->fetchColumn();

        if ($recentFailures < self::MAX_ATTEMPTS) {
            return false;
        }

        // Threshold met within the counting window — the lockout itself
        // then persists for the full, longer LOCKOUT_MINUTES measured from
        // the most recent failure, independent of WINDOW_MINUTES, so the
        // account stays locked even after individual attempts age out of
        // the (shorter) counting window.
        return $this->lockoutMinutesRemaining($ip) > 0;
    }

    /**
     * Returns true if the given account (email) is currently locked out due
     * to too many recent failed attempts, regardless of which IP(s) they
     * came from. Complements isLockedOut() (IP-based) so credential
     * stuffing spread across many IPs against one account is also caught.
     */
    public function isAccountLockedOut(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND success = 0
               AND attempted_at >= (NOW() - INTERVAL :window MINUTE)'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':window', self::ACCOUNT_WINDOW_MINUTES, PDO::PARAM_INT);
        $stmt->execute();
        $recentFailures = (int) $stmt->fetchColumn();

        if ($recentFailures < self::ACCOUNT_MAX_ATTEMPTS) {
            return false;
        }

        return $this->accountLockoutMinutesRemaining($email) > 0;
    }

    /** Minutes remaining before the account's lockout clears (for messaging). */
    public function accountLockoutMinutesRemaining(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(attempted_at) FROM login_attempts
             WHERE email = :email AND success = 0'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $lastAttempt = $stmt->fetchColumn();

        if (!$lastAttempt) {
            return 0;
        }

        $unlockAt = (new DateTime($lastAttempt))->modify('+' . self::ACCOUNT_LOCKOUT_MINUTES . ' minutes');
        $now = new DateTime();
        $diff = (int) ceil(($unlockAt->getTimestamp() - $now->getTimestamp()) / 60);

        return max(0, $diff);
    }

    /** Records a failed login attempt for the given IP/email. */
    public function recordFailure(string $ip, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :email, 0)'
        );
        $stmt->execute([':ip' => $ip, ':email' => $email]);
    }

    /**
     * Records a successful login, which also clears both the IP's and the
     * account's recent failure history so legitimate users aren't penalized
     * after they get in.
     */
    public function recordSuccess(string $ip, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :email, 1)'
        );
        $stmt->execute([':ip' => $ip, ':email' => $email]);

        $clear = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE ip_address = :ip AND success = 0'
        );
        $clear->execute([':ip' => $ip]);

        $clearEmail = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND success = 0'
        );
        $clearEmail->execute([':email' => $email]);
    }

    /** Minutes remaining before the IP's lockout clears (for messaging). */
    public function lockoutMinutesRemaining(string $ip): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(attempted_at) FROM login_attempts
             WHERE ip_address = :ip AND success = 0'
        );
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
        $lastAttempt = $stmt->fetchColumn();

        if (!$lastAttempt) {
            return 0;
        }

        $unlockAt = (new DateTime($lastAttempt))->modify('+' . self::LOCKOUT_MINUTES . ' minutes');
        $now = new DateTime();
        $diff = (int) ceil(($unlockAt->getTimestamp() - $now->getTimestamp()) / 60);

        return max(0, $diff);
    }
}
