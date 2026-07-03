# Deployment Notes — Sharek v1.6

## ⚠️ CRITICAL: Credential Rotation Required

After deploying to production, you **MUST** rotate all exposed credentials immediately.

### Exposed Credentials (Must Rotate)

1. **ADMIN_PASSWORD_HASH**
   - Generate a new bcrypt hash:
     ```php
     echo password_hash('YourNewStrongPassword', PASSWORD_DEFAULT);
     ```
   - Replace the hash value in `.env`
   - Use a minimum 12-character password with mixed case, numbers, and symbols

2. **DB_PASS** (Database Password)
   - Change via your hosting provider's control panel (InfinityFree, cPanel, etc.)
   - Update `.env` with the new password
   - Generate a strong random password (20+ characters)

3. **SMTP_PASS** (Email Password)
   - Change or regenerate app-specific password via your email provider
   - Update `.env` with the new password

4. **CRON_SECRET** (Cron Job Security Secret)
   - Generate a new random string (32+ characters)
   - Update `.env` and your cron-job.org job settings with the new secret

5. **MAPTILER_API_KEY** (Map Tile Key)
   - Found in `js/app.js` line ~13 (intentionally client-visible but restrict via domain allowlist)
   - Log in at https://cloud.maptiler.com/account/keys
   - Add "Allowed HTTP Origins" restriction to your production domain
   - Free tier: 100,000 map loads/month

---

## Production Deployment Checklist

### Security
- [ ] Rotate `ADMIN_PASSWORD_HASH` in `.env`
- [ ] Rotate `DB_PASS` via hosting panel
- [ ] Rotate `SMTP_PASS` via email provider
- [ ] Rotate `CRON_SECRET`
- [ ] Restrict `MAPTILER_API_KEY` to your domain at maptiler.com
- [ ] Verify `.env` is not web-accessible: `curl -i https://yourdomain.com/.env` must return 403
- [ ] Verify `database_setup/` is not web-accessible: `curl -i https://yourdomain.com/database_setup/sharek_db.sql` must return 403
- [ ] Verify `Database.php` is not directly downloadable: must return 403

### Database
- [ ] Run `database_setup/sharek_db.sql` on fresh install (creates all tables)
- [ ] For existing databases: run all 4 migrations in `migrations/` in order
- [ ] Verify `login_attempts` table exists (required for login rate limiting)

### Application
- [ ] Test admin login with new password
- [ ] Test user registration + OTP email delivery
- [ ] Test password reset flow
- [ ] Test trip creation and booking
- [ ] Verify HTTPS is working (cookies should be Secure)
- [ ] Check `.htaccess` protections are active (test with curl)
- [ ] Set up cron job at cron-job.org (daily at 02:00, with `X-Cron-Secret` header)

### Performance
- [ ] Verify Gzip compression is active (`curl -H "Accept-Encoding: gzip" -I https://yourdomain.com/`)
- [ ] Verify CSS/JS caching headers are set (1-year `Cache-Control`)

---

## Cron Job Setup (cron-job.org)

InfinityFree does **not** support server cron jobs. Use the free https://cron-job.org service:

1. Create account at cron-job.org
2. Create new cronjob:
   - **URL**: `https://yourdomain.com/cron_cleanup`
   - **Schedule**: Daily at 02:00
   - **Request method**: GET
   - **Custom header**: `X-Cron-Secret: YOUR_CRON_SECRET`
3. Replace `YOUR_CRON_SECRET` with the value of `CRON_SECRET` in your `.env`

The cron script cleans up:
- Completed trips older than 1 year (frees DB space)
- Login attempt records older than 1 day (keeps rate-limit table lean)

---

## .env File Template

```ini
DB_HOST=your_db_host
DB_NAME=sharek_db
DB_USER=your_db_user
DB_PASS=your_strong_db_password

SMTP_HOST=smtp.yourprovider.com
SMTP_USER=your@email.com
SMTP_PASS=your_smtp_app_password
SMTP_PORT=587

ADMIN_PASSWORD_HASH=your_bcrypt_hash_here
CRON_SECRET=your_32_char_random_secret
EMAIL_TOKEN_TTL_HOURS=24
APP_URL=https://yourdomain.com
ADMIN_EMAIL=admin@yourdomain.com
FCM_SERVICE_ACCOUNT_JSON=
MAPTILER_API_KEY=your_maptiler_key_here
```

---

## Emergency Procedures

### If Credentials Are Compromised
1. Immediately rotate all credentials in `.env`
2. Review server access logs for suspicious activity
3. Check database for unauthorized changes
4. Review user accounts for new/modified admin accounts

### If Admin Access Is Lost
1. Access database directly via phpMyAdmin or hosting panel
2. Run: `UPDATE users SET password = '<new_hash>' WHERE email = 'your_admin_email'`
3. Generate the hash with: `echo password_hash('NewPassword', PASSWORD_DEFAULT);`

---

## File Permissions (Linux/Unix Hosting)

```bash
chmod 600 .env          # Owner read/write only
chmod 644 *.php         # Owner write, world read
chmod 755 css/ js/      # Directories need execute bit
chmod 644 css/* js/*    # Static assets
```
