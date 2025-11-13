<?php
/**
 * Inmate360 Configuration File
 * Clayton County Jail & Court Analytics Platform
 * UPDATED: With SQLite Membership System
 */

// Database Configuration
define('DB_PATH', __DIR__ . '/jail_data.db');
define('DB_BACKUP_PATH', __DIR__ . '/backups');
define('DB_BACKUP_RETENTION_DAYS', 30);

// Check if PDO SQLite driver is available
function checkPDOSQLite() {
    if (!extension_loaded('pdo_sqlite')) {
        echo "ERROR: PDO SQLite driver not found!\n\n";
        echo "Please install the SQLite extension:\n";
        echo "Ubuntu/Debian: sudo apt-get install php-sqlite3\n";
        echo "macOS: brew install php\n";
        echo "Windows: Enable extension=pdo_sqlite in php.ini\n\n";

        echo "Available PDO drivers:\n";
        print_r(PDO::getAvailableDrivers());
        echo "\n";

        return false;
    }
    return true;
}

// Jail Scraper Configuration - Multiple URLs
define('SCRAPE_URLS', [
    'Active_Inmates_All_Time' => 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj201r.pgm',
    '31_Day_Docket' => 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj210r.pgm?days=31&rtype=F',
    '48_Hour_Docket' => 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj210r.pgm?days=02&rtype=F'
]);

// Court Scraper Configuration
define('COURT_SCRAPE_BASE_URL', 'https://weba.claytoncountyga.gov/casinqcgi-bin/wci201r.pgm?ctt=A&dvt=C&cyr=2024&ctp=WA&csq=78533&lname=TATUM&fname=TESSA&mname=ELIZABETH&sname=&pname=&rtype=P,');

// Inmate Detail Scraper Configuration
define('INMATE_DETAIL_BASE_URL', 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj100r.pgm');

// Scraper Timing Configuration
define('SCRAPE_INTERVAL', 3600); // 1 hour in seconds
define('SCRAPE_DURATION', 172800); // 48 hours in seconds
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('TIMEOUT', 30);
define('MAX_RETRIES', 3);
define('RETRY_DELAY', 5); // seconds

// Site Configuration
define('SITE_URL', 'http://inmate360.com:8000');
define('SITE_NAME', 'Inmate360');
define('SITE_DESCRIPTION', 'Unified Jail & Court Analytics Platform - Clayton County');
define('SITE_VERSION', '1.0.0');
define('SITE_EMAIL', 'noreply@inmate360.com');

// Logging Configuration
define('LOG_FILE', __DIR__ . '/scraper.log');
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('LOG_ROTATION_COUNT', 5);

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', LOG_FILE);

// Timezone
date_default_timezone_set('America/New_York');

// Admin Configuration
define('ADMIN_PASSWORD', 'admin123'); // Change this!
define('REQUIRE_INVITE', true);
define('ADMIN_EMAIL', 'admin@inmate360.com');

// Security Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('BETA_CODE_LENGTH', 12);
define('LOCKOUT_DURATION', 900);

// Pagination
define('DEFAULT_PER_PAGE', 30);
define('MAX_PER_PAGE', 100);

// Cross-linking Configuration
define('ENABLE_CROSS_LINKING', true);
define('AUTO_LINK_SIMILARITY_THRESHOLD', 70); // Percentage for name matching
define('MANUAL_LINK_EXPIRY', 30); // Days for manual link verification

// Invite System Configuration
define('DEFAULT_INVITE_MAX_USES', 100);
define('DEFAULT_INVITE_EXPIRY_DAYS', 30);
define('INVITE_CODE_LENGTH', 12);

// Cache Configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour
define('CACHE_DIR', __DIR__ . '/cache');

// Email Configuration (for future use)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_ENCRYPTION', 'tls');
define('FROM_EMAIL', 'noreply@inmate360.com');
define('FROM_NAME', 'Inmate360 System');

// API Configuration (for future use)
define('API_ENABLED', false);
define('API_KEY', '');
define('API_RATE_LIMIT', 100); // requests per hour

// Maintenance Configuration
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'System is currently under maintenance. Please check back later.');

// Performance Configuration
define('QUERY_TIMEOUT', 30); // seconds
define('MEMORY_LIMIT', '256M');
define('MAX_EXECUTION_TIME', 300); // 5 minutes

// Development Configuration
define('DEBUG_MODE', false);
define('SHOW_SQL_QUERIES', false);
define('PROFILING_ENABLED', false);

// Case Detail Scraper Configuration
define('CASE_DETAIL_CACHE_HOURS', 24); // Cache case details for 24 hours
define('CASE_DETAIL_MAX_RETRIES', 2);
define('CASE_DETAIL_RETRY_DELAY', 3); // seconds between retries

// ===== MEMBERSHIP SYSTEM CONFIGURATION =====

define('MEMBERSHIP_ENABLED', true); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);

// Membership Tiers
define('MEMBERSHIP_TIERS', [
    'community' => [
        'name' => 'Community',
        'description' => 'Free community access',
        'features' => ['search', 'view_basic'],
        'max_searches_per_day' => 50
    ],
    'beta' => [
        'name' => 'Beta',
        'description' => 'Beta program access',
        'features' => ['search', 'view_detailed', 'export', 'alerts'],
        'max_searches_per_day' => 500
    ],
    'law_enforcement' => [
        'name' => 'Law Enforcement',
        'description' => 'Law enforcement professional access',
        'features' => ['search', 'view_detailed', 'export', 'alerts', 'batch_import', 'admin_tools'],
        'max_searches_per_day' => -1 // Unlimited
    ],
    'admin' => [
        'name' => 'Administrator',
        'description' => 'Full system access',
        'features' => ['all'],
        'max_searches_per_day' => -1 // Unlimited
    ]
]);

// ===== DATABASE CONNECTION FUNCTION =====

/**
 * Get SQLite database connection
 * Initializes membership tables if needed
 */
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            // Ensure database directory exists
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                @mkdir($dbDir, 0755, true);
            }

            // Create PDO connection
            $db = new PDO(
                'sqlite:' . DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5000,
                ]
            );

            // Enable foreign keys
            $db->exec('PRAGMA foreign_keys = ON');
            
            // Set WAL mode for better concurrency
            $db->exec('PRAGMA journal_mode = WAL');

            // Initialize schema if needed
            initializeMembershipSchema($db);

        } catch (PDOException $e) {
            error_log("SQLite connection error: " . $e->getMessage());
            die("Database connection failed. Check logs for details.");
        }
    }
    
    return $db;
}

/**
 * Initialize membership database schema
 */
function initializeMembershipSchema($db) {
    try {
        // Check if users table exists
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        if ($result && $result->rowCount() > 0) {
            return; // Schema already initialized
        }

        // Create users table
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                first_name TEXT,
                last_name TEXT,
                phone TEXT,
                role TEXT DEFAULT 'user',
                status TEXT DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login_at TIMESTAMP,
                login_attempts INTEGER DEFAULT 0,
                locked_until TIMESTAMP
            )
        ");

        // Create memberships table
        $db->exec("
            CREATE TABLE IF NOT EXISTS memberships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER UNIQUE NOT NULL,
                tier TEXT DEFAULT 'community',
                beta_code TEXT,
                beta_code_used_at TIMESTAMP,
                status TEXT DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Create contact_submissions table
        $db->exec("
            CREATE TABLE IF NOT EXISTS contact_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT,
                subject TEXT NOT NULL,
                message TEXT NOT NULL,
                inquiry_type TEXT DEFAULT 'general',
                status TEXT DEFAULT 'new',
                ip_address TEXT,
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        // Create login_audit table
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                email TEXT,
                ip_address TEXT,
                user_agent TEXT,
                success INTEGER DEFAULT 0,
                failure_reason TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        // Create beta_codes table
        $db->exec("
            CREATE TABLE IF NOT EXISTS beta_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                tier TEXT DEFAULT 'community',
                max_uses INTEGER DEFAULT 1,
                current_uses INTEGER DEFAULT 0,
                created_by INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP,
                status TEXT DEFAULT 'active'
            )
        ");

        // Create indices for performance
        $db->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memberships_user_id ON memberships(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memberships_tier ON memberships(tier)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_email ON contact_submissions(email)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_status ON contact_submissions(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_audit_user ON login_audit(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_beta_codes_code ON beta_codes(code)");

        logMessage('INFO', 'Membership schema initialized successfully');

    } catch (Exception $e) {
        error_log("Schema initialization error: " . $e->getMessage());
    }
}

/**
 * Log message to file and system
 */
function logMessage($level, $message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message";
    
    if (!empty($context)) {
        $logMessage .= " " . json_encode($context);
    }
    
    error_log($logMessage);
}

/**
 * Get current user from session
 */
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['email'] ?? '',
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'tier' => $_SESSION['tier'] ?? 'community'
    ];
}

/**
 * Check user session and tier access
 */
function requireAuth($allowedTiers = []) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    // Check tier access if specified
    if (!empty($allowedTiers)) {
        $userTier = $_SESSION['tier'] ?? 'community';
        if (!in_array($userTier, $allowedTiers, true)) {
            http_response_code(403);
            die('Access denied. Your membership tier does not have permission for this resource.');
        }
    }
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Check if user has permission for feature
 */
function hasFeature($feature) {
    $tier = $_SESSION['tier'] ?? 'community';
    $tierFeatures = MEMBERSHIP_TIERS[$tier]['features'] ?? [];
    
    return in_array('all', $tierFeatures) || in_array($feature, $tierFeatures);
}

/**
 * Check daily search limit
 */
function checkSearchLimit() {
    $tier = $_SESSION['tier'] ?? 'community';
    $limit = MEMBERSHIP_TIERS[$tier]['max_searches_per_day'] ?? 0;
    
    if ($limit === -1) {
        return true; // Unlimited
    }

    // TODO: Implement search count tracking
    return true;
}
?>