<?php
/**
 * Sharek v1.5 - Database Connection Class
 * 
 * @file Database.php
 * @date 2026-05-25
 * @description Handles secure PDO database connections with UTF-8 support and proper error handling
 * @version 1.5.0
 */

// Centralized error-reporting policy (audit finding #15). This used to be
// set independently here (error_reporting(E_ALL)) AND in .htaccess
// (php_value error_reporting 0). Since Database.php is required by nearly
// every entry point, its runtime call ran *after* .htaccess's directive
// and silently won, so the "fully suppress errors in production" policy
// declared in .htaccess was never actually in effect.
//
// This is now the single source of truth: PHP always reports E_ALL
// internally so every issue gets logged, but errors are never displayed
// to visitors. .htaccess no longer sets error_reporting/display_errors,
// to avoid the two settings drifting out of sync again.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

class Database {
    /**
     * Database configuration - loaded from environment or config
     * @var string
     */
    private $host;
    private $dbName;
    private $username;
    private $password;
    private $charset;

    /**
     * PDO connection instance
     * @var PDO|null
     */
    public $pdo;

    /**
     * Constructor - Initialize database connection
     * 
     * Loads configuration from environment variables or uses defaults
     * Establishes secure PDO connection with UTF-8 support
     */
    public function __construct() {
        $this->loadConfig();
        $this->connect();
    }

    /**
     * Load database configuration from .env file
     * 
     * @return void
     */
    private function loadConfig() {
        $env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);
        if ($env === false) {
            // Fallback for InfinityFree open_basedir restrictions
            $env = $this->parseEnvFallback(__DIR__ . '/.env');
        }
        if ($env === false) {
            throw new RuntimeException('Configuration unavailable');
        }

        // Fail closed on missing/empty required keys (audit finding #31).
        // Direct array access on a missing key used to raise a PHP warning
        // and silently resolve to null, instead of failing clearly through
        // the same RuntimeException path used everywhere else in this
        // method — e.g. a typo'd key, or a partially-filled .env.example
        // copied as-is, would only surface as a confusing PDO connection
        // error downstream.
        $requiredKeys = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($requiredKeys as $requiredKey) {
            if (!isset($env[$requiredKey]) || $env[$requiredKey] === '') {
                throw new RuntimeException('Configuration unavailable');
            }
        }

        $this->host = $env['DB_HOST'];
        $this->dbName = $env['DB_NAME'];
        $this->username = $env['DB_USER'];
        $this->password = $env['DB_PASS'];
        $this->charset = 'utf8mb4';

        /**
         * Fail-closed credential validation.
         *
         * Refuses to connect if known-exposed / placeholder credentials are
         * detected in .env. This runs for every entry point that constructs
         * Database directly (login.php, admin.php, cron_cleanup.php, etc.),
         * not just api.php.
         */
        $knownExposedValues = [
            '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // Default sample hash
            'your_database_password_here',
            'your_smtp_password_here',
            'your_cron_secret_here',
        ];

        foreach ($knownExposedValues as $exposedValue) {
            if (in_array($exposedValue, [$this->password, $env['DB_PASS'] ?? '', $env['SMTP_PASS'] ?? '', $env['CRON_SECRET'] ?? ''], true)) {
                throw new RuntimeException('SECURITY ERROR: Application is running with exposed placeholder credentials. Please rotate all credentials in .env file. See DEPLOYMENT_NOTES.md for instructions.');
            }
        }
    }

    /**
     * Fallback parser for .env file when parse_ini_file fails
     * Used for InfinityFree compatibility with open_basedir restrictions
     * 
     * @param string $file Path to .env file
     * @return array|false Parsed configuration or false on failure
     */
    private function parseEnvFallback($file) {
        if (!file_exists($file) || !is_readable($file)) {
            return false;
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty($line) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
        
        return $env;
    }

    /**
     * Establish database connection using PDO
     * 
     * Creates secure PDO connection with:
     * - Exception error mode
     * - Associative array fetch mode
     * - Native prepared statements
     * - UTF-8 character set
     * 
     * @return void
     * @throws PDOException If connection fails
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbName};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
            // Ensure UTF-8 encoding
            $this->pdo->exec("SET NAMES 'utf8mb4'");
            $this->pdo->exec("SET CHARACTER SET utf8mb4");
            $this->pdo->exec("SET COLLATION_CONNECTION = utf8mb4_unicode_ci");
            
        } catch (PDOException $e) {
            $this->handleConnectionError($e);
        }
    }

    /**
     * Handle database connection errors
     * 
     * Logs error details and displays JSON error message
     * 
     * @param PDOException $e The exception object
     * @return void
     */
    private function handleConnectionError(PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە']));
    }

    /**
     * Get PDO connection instance
     * 
     * Returns the active PDO connection for use in queries
     * 
     * @return PDO|null The PDO connection object
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Close database connection
     * 
     * Sets PDO instance to null to release connection
     * 
     * @return void
     */
    public function closeConnection() {
        $this->pdo = null;
    }

    /**
     * Destructor - Ensure connection is closed
     * 
     * Automatically closes connection when object is destroyed
     * 
     * @return void
     */
    public function __destruct() {
        $this->closeConnection();
    }
}
