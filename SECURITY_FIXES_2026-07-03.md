# Security Fixes — 2026-07-03

Follow-up pass after `docs/SECURITY_AUDIT.md` (2026-06-28) and the earlier
`COOKIE_SECURITY_SUMMARY.md` / `ERROR_SANITIZATION_SUMMARY.md` fixes. That
earlier work was verified against the live code (most of it held up), and
the issues below — found by reading the actual code paths, not just the
docs — have now been fixed and tested against a real MySQL/MariaDB instance.

## 1. Unauthenticated driver-reputation disclosure — `api.php`
`getDriverReputation()` never called `requireAuth()`. Its ownership check
was wrapped in `if ($currentUserId > 0 && ...)`, so for an anonymous
visitor (`$currentUserId === 0`) the check was skipped entirely — any
visitor with just a page-load session (no login) could query any
`driver_id` and get back that driver's name, verification status, and
reputation, and use it to enumerate valid user IDs.
**Fix:** added `$currentUserId = $this->requireAuth();` at the top, same as
every other protected endpoint.

## 2. Primary login endpoint had zero rate limiting — `api.php`
The login the app actually calls (`js/app.js` → `apiFetch('login', ...)` →
`api.php::login()`) had **no brute-force protection at all** — not even
IP-based — while the separate `login.php` HTML page did. This was the real
unprotected attack surface, since the SPA never uses `login.php`.
**Fix:** added the same `RateLimiter`-based IP + account lockout used in
`login.php`.

## 3. No account-level lockout, only IP-based — `login.php` / `RateLimiter`
An attacker spreading login guesses across many IPs against one specific
victim account was not throttled — only per-IP counting existed.
**Fix:** added `RateLimiter::isAccountLockedOut()` /
`accountLockoutMinutesRemaining()` (8 failures / 15 min, keyed by email
across all IPs) and wired it into both `login.php` and `api.php::login()`.
`recordSuccess()` now also clears the account's failure history, not just
the IP's.

## 4. Registration OTP verification had no brute-force protection — `api.php`
`verifyRegistrationOTP()` checked the 6-digit code with `hash_equals()` but
had no attempt counter — unlike the equivalent password-reset OTP flow in
`forgot_password_handler.php`, which already locks after 5 failures.
**Fix:** applied the same DB-backed 5-attempts/15-minute lockout, keyed by
IP + `reg_otp:<user_id>`, reusing the existing `login_attempts` table (no
new migration needed).

## 5. Registration OTP could be used to spam a victim's inbox — `api.php`
`sendRegistrationOTP()` (the *initial* send) had no rate limit and
resends an OTP email every time it's called for the same unverified email
(via `ON DUPLICATE KEY UPDATE`). Only the separate `resendRegistrationOTP()`
action had a cap.
**Fix:** applied the same 3-per-10-minute cap to the initial send, keyed by
IP + `reg_send:<email>`.

## 6. `.htaccess` file-blocking used Apache-2.2-only syntax
`Order Allow,Deny` / `Deny from all` requires `mod_access_compat` on
Apache 2.4 — without it, the directive is silently ignored (no error, it
just doesn't block anything), which could leave `.env`, `Database.php`,
`EmailService.php`, etc. directly downloadable depending on the host's
Apache build.
**Fix:** each blocking rule now tries `Require all denied` (Apache 2.4
native, `mod_authz_core`) first and falls back to the legacy directive only
if that module isn't present.
**Action required after deploying:** verify directly —
`curl -i https://yourdomain/.env` must return 403, not file contents.

## Testing performed
- `php -l` across every `.php` file in the project — clean.
- Stood up a real MariaDB instance, created the `login_attempts` table from
  migration 004, and ran functional tests against the actual `RateLimiter`
  class: IP lockout, account lockout across multiple IPs (the fix), lockout
  clearing on success, and lockout-minutes-remaining bounds — all passed.
- Ran the exact SQL used in each new code path (OTP attempt counting, OTP
  send-rate counting, driver-reputation ownership check) directly against
  the test database to confirm syntax and logic.
- Could not spin up a full HTTP+session integration test of `api.php`
  itself in this environment; the new code in `login()`,
  `verifyRegistrationOTP()`, and `sendRegistrationOTP()` mirrors the
  already-tested `forgot_password_handler.php` / `RateLimiter` patterns
  exactly, using the verified SQL above. Recommend a quick manual
  smoke-test of login, registration, and password reset after deploying.

## Not changed / still worth knowing
- OTPs are still 6 digits. With the throttling now applied everywhere
  (login, registration send, registration verify, password reset), this is
  a reasonable tradeoff, not a gap.
- There is no SMS in this system — all OTPs are email-only, sent via
  `EmailService.php` / PHPMailer.

---

# Follow-up — same day

## 7. Contact form had no rate limiting — `contact_handler.php`
CSRF-protected and properly output-escaped, but nothing stopped repeated
submissions — each one sends an admin email and appends to a log file.
**Fix:** added the same DB-backed throttle pattern (5 submissions / 10
minutes per IP, reusing `login_attempts`). Fails open (allows the
submission) if the rate-limit check itself errors, since this is a spam
guard, not an auth control. Verified against a real DB: attempts 1–5
allowed, 6th blocked.

## 8. Stale `.htaccess` reference in `install_db.php`'s comment
The deployment-instructions comment quoted an old `.htaccess` pattern that
no longer matched the actual file (harmless — a comment, not executed —
but confusing to follow during a real deploy). Updated to match the
current dual-syntax block.

## 9. `database_setup/.htaccess` — added legacy Apache fallback
This directory-level block (which fully blocks `database_setup/` as
defense-in-depth on top of the root rules) used only the Apache 2.4
`Require all denied` syntax. Added the same 2.2 fallback pattern used in
the root `.htaccess` for consistency across hosts.
