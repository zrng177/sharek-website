<?php
/**
 * ⚠️ CRITICAL SECURITY WARNING ⚠️
 * 
 * This file MUST be deleted from the production server after the first deployment.
 * Leaving this file accessible allows anyone to:
 * - Reinstall/recreate database tables (DATA LOSS)
 * - Execute arbitrary SQL commands
 * - Compromise the entire application
 * 
 * DELETION INSTRUCTIONS:
 * 1. After successful database installation, delete this file immediately
 * 2. Do NOT rely solely on .htaccess protection
 * 3. Delete both this file AND sharek_db.sql from production
 * 4. Keep backups in a secure, off-server location only
 * 
 * This is a one-time installation tool. It has no legitimate purpose in production.
 */

// Safety guard — remove or comment out after first install
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes_i_know') {
    die('این فایل تنها یەکجار بەکاردێت. بۆ بەکارهێنانی: ?confirm=yes_i_know زیاد بکە');
}

/**
 * DEPLOYMENT INSTRUCTIONS (InfinityFree):
 *
 * This file is blocked by .htaccess for security.
 * To run the database installer during first deploy:
 *
 * STEP 1 — Temporarily comment out the block in .htaccess:
 *   Find:
 *     <FilesMatch "^(install_db\.php|Database\.php|EmailService\.php|test_email\.php|test\.php|debug\.php)$">
 *         <IfModule mod_authz_core.c>
 *             Require all denied
 *         </IfModule>
 *         <IfModule !mod_authz_core.c>
 *             Order Allow,Deny
 *             Deny from all
 *         </IfModule>
 *     </FilesMatch>
 *   Comment it out by adding # before each line, then save and re-upload .htaccess.
 *   (Commenting out the whole block also temporarily unblocks Database.php
 *   and EmailService.php — that's fine for the few minutes install takes,
 *   just don't skip Step 3.)
 *
 * STEP 2 — Visit: https://yourdomain.com/database_setup/install_db.php
 *   Click the install button to run sharek_db.sql
 *
 * STEP 3 — Restore .htaccess: remove the # comments you added in Step 1,
 *   then re-upload .htaccess. This re-blocks the installer.
 *
 * STEP 4 — DELETE THIS FILE from production server immediately after successful installation.
 *
 * WARNING: Never leave install_db.php publicly accessible in production.
 */

/**
 * Sharek v1.5 - Database Installation
 * 
 * @file install_db.php
 * @date 2026-05-26
 * @description Automatic database schema installation
 * @version 1.5.0
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CSRF protection (audit finding #17) — previously the only gate on this
// destructive endpoint was a static, hardcoded ?confirm=yes_i_know query
// string visible in this very file (security-through-obscurity, not real
// access control). Add the same CSRF-token pattern used by every other
// form in the app (login, register, admin, contact, forgot-password).
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;
SecurityManager::initSecureSession();

require_once __DIR__ . '/Database.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    // Validate CSRF token before running any SQL (audit finding #17).
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە';
    } else {
    try {
        $db = new Database();
        $pdo = $db->getConnection();
        
        // Read sharek_db.sql file
        $schemaFile = __DIR__ . '/sharek_db.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception('sharek_db.sql file not found');
        }
        
        $sql = file_get_contents($schemaFile);
        
        // Split SQL by semicolons
        $statements = explode(';', $sql);
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                try {
                    $pdo->exec($statement);
                    $successCount++;
                } catch (PDOException $e) {
                    // Ignore duplicate table errors
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        $errorCount++;
                        error_log('SQL Error: ' . $e->getMessage());
                    }
                }
            }
        }
        
        if ($successCount > 0) {
            $message = "داتابەیس بە سەرکەوتوویی دروست کرا! ($successCount statement executed)";
        } else {
            $message = "هیچ گۆڕانکارییەک پێویست نەبوو - داتابەیس ئامادەیە.";
        }
        
    } catch (Exception $e) {
        $error = 'هەڵە: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دابەزاندنی داتابەیس | شەریک</title>
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        .install-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 1.5rem;
        }

        .install-card {
            background: var(--bg-card);
            padding: 2.5rem;
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 500px;
            text-align: center;
        }

        .install-header {
            margin-bottom: 2rem;
        }

        .install-header h1 {
            color: var(--navy);
            margin: 0 0 0.5rem 0;
            font-size: 1.75rem;
        }

        .install-header p {
            color: var(--text-muted);
            margin: 0;
        }

        .info-box {
            background: var(--bg-card-alt);
            padding: 1rem;
            border-radius: var(--r-md);
            margin-bottom: 1.5rem;
            text-align: right;
            font-size: 0.9rem;
            color: var(--text-body);
        }

        .btn-submit {
            width: 100%;
            padding: 0.875rem;
            background: var(--navy);
            color: white;
            border: none;
            border-radius: var(--r-md);
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-submit:hover {
            background: var(--navy-mid);
        }

        .error-message {
            background: var(--danger-bg);
            color: var(--danger);
            padding: 0.75rem;
            border-radius: var(--r-md);
            margin-bottom: 1.25rem;
            text-align: center;
        }

        .success-message {
            background: var(--success-bg);
            color: var(--success);
            padding: 0.75rem;
            border-radius: var(--r-md);
            margin-bottom: 1.25rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-card">
            <div class="install-header">
                <h1>🗄️ دابەزاندنی داتابەیس</h1>
                <p>بەڕێوەبەرانی sharek_db.sql بۆ شەریک</p>
            </div>
            
            <div class="info-box">
                <strong>تێبینی:</strong> ئەم فایلە sharek_db.sql بەسەر داتابەیسەکە دەبەیت بۆ دروستکردنی ستوونە نوێیەکان.
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="success-message"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="install" class="btn-submit">دابەزاندنی داتابەیس</button>
            </form>
            
            <div style="text-align: center; margin-top: 1.5rem;">
                <a href="index" style="color: var(--text-muted); text-decoration: none; font-size: 0.875rem;">گەڕانەوە بۆ ماڵپەڕ</a>
            </div>
        </div>
    </div>
</body>
</html>
