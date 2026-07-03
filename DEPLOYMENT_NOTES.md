# Deployment Security Notes

## ⚠️ CRITICAL: Credential Rotation Required

After deploying to production, you MUST rotate all exposed credentials immediately.

### Exposed Credentials (Must Rotate)

The following credentials have been exposed in version control and must be changed:

1. **ADMIN_PASSWORD_HASH**
   - Run: `php generate_admin_hash.php`
   - Replace the hash in .env with the generated one
   - Use a strong, unique password (minimum 12 characters, mixed case, numbers, symbols)
   - Store the new password securely (password manager, not in code)

2. **DB_PASS** (Database Password)
   - Change via your hosting provider's control panel (InfinityFree, cPanel, etc.)
   - Update the .env file with the new password
   - Database user: typically the same as your hosting account username
   - Generate a strong random password (20+ characters)

3. **SMTP_PASS** (Email Password)
   - Change via your email service provider (Gmail, Outlook, etc.)
   - If using app-specific passwords, generate a new one
   - Update the .env file with the new password
   - Enable 2FA on your email account if not already enabled

4. **CRON_SECRET** (Cron Job Security Secret)
   - Generate a new random string (32+ characters, mixed case, numbers, symbols)
   - Update the .env file with the new secret
   - This secret protects cron jobs from unauthorized execution

## Rotation Process

### Step 1: Generate New Admin Hash
```bash
php generate_admin_hash.php
```

### Step 2: Update Hosting Provider Credentials
1. Log into your hosting control panel (InfinityFree, cPanel, etc.)
2. Change database password
3. Update email password or generate new app-specific password
4. Generate new cron secret

### Step 3: Update .env File
Replace all credential values in .env with the new ones:
```
ADMIN_PASSWORD_HASH=your_new_generated_hash_here
DB_PASS=your_new_db_password_here
SMTP_PASS=your_new_smtp_password_here
CRON_SECRET=your_new_random_secret_here
```

### Step 4: Delete Helper Script
```bash
rm generate_admin_hash.php
```

### Step 5: Test Application
- Test admin login with new password
- Test email functionality
- Test database operations
- Test cron jobs if applicable

## Security Best Practices

### Password Requirements
- Minimum 12 characters
- Mix of uppercase, lowercase, numbers, and symbols
- No dictionary words or common patterns
- Unique for each service (don't reuse passwords)

### Storage Requirements
- Store credentials in password manager (1Password, Bitwarden, etc.)
- Never commit credentials to version control
- Never share credentials via email or chat
- Rotate credentials regularly (every 90 days recommended)

### .env File Security
- .env should be in .gitignore (already configured)
- Never upload .env to public repositories
- Set proper file permissions (600: read/write for owner only)
- Backup .env securely (encrypted storage)

## Production Deployment Checklist

- [ ] Rotate ADMIN_PASSWORD_HASH
- [ ] Rotate DB_PASS via hosting panel
- [ ] Rotate SMTP_PASS via email provider
- [ ] Rotate CRON_SECRET
- [ ] Delete install_db.php from production
- [ ] Delete sharek_db.sql from production
- [ ] Delete generate_admin_hash.php
- [ ] Verify .env is not accessible via web
- [ ] Test admin login with new password
- [ ] Test email functionality
- [ ] Test database operations
- [ ] Verify HTTPS is working (cookies should be secure)
- [ ] Check .htaccess protections are active

## Emergency Procedures

### If Credentials Are Compromised
1. Immediately rotate all credentials
2. Review access logs for suspicious activity
3. Check for unauthorized database changes
4. Review user accounts for any new/modified accounts
5. Enable additional monitoring/alerting

### If Admin Access Is Lost
1. Access database directly via phpMyAdmin or hosting panel
2. Manually update admin password hash in users table
3. Use password_hash() with new password
4. Test login with new credentials

## Contact & Support

If you discover a security vulnerability:
- Immediately rotate all credentials
- Review audit logs
- Contact security team if applicable
- Document the incident for future reference
