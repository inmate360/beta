<?php
/**
 * Inmate360 - Complete Installation Script
 * This script initializes the entire database, including all tables, indexes, and default data.
 * It should be run from the command line (CLI) for a first-time setup.
 *
 * Usage: php install.php
 */

require_once 'config.php';

// --- Terminal Output Helpers ---
class Colors {
    public static $GREEN = "\033[32m";
    public static $RED = "\033[31m";
    public static $YELLOW = "\033[33m";
    public static $BLUE = "\033[34m";
    public static $RESET = "\033[0m";
}

function log_message($message, $color = null) {
    $timestamp = date('Y-m-d H:i:s');
    $colored_message = $color ? $color . $message . Colors::$RESET : $message;
    echo "[$timestamp] $colored_message\n";
}

function log_success($message) { log_message("✓ $message", Colors::$GREEN); }
function log_error($message) { log_message("✗ $message", Colors::$RED); }
function log_info($message) { log_message("ℹ $message", Colors::$BLUE); }
function log_warning($message) { log_message("⚠ $message", Colors::$YELLOW); }

/**
 * Main database setup function.
 */
function setup_database() {
    log_info("==================================================");
    log_info("  Inmate360 Complete Database Installation");
    log_info("==================================================\n");

    $drop_existing = false;

    if (file_exists(DB_PATH)) {
        log_warning("Database file already exists at: " . DB_PATH);
        echo "\nChoose an option:\n";
        echo "  [1] Re-create all tables (DESTRUCTIVE: All current data will be lost).\n";
        echo "  [2] Add missing tables only (Safe: Keeps existing data).\n";
        echo "  [3] Cancel installation.\n";
        echo "Your choice [1-3]: ";
        
        $choice = trim(fgets(STDIN));
        
        if ($choice === '1') {
            log_warning("This will ERASE ALL DATA in the database!");
            echo "To confirm, type 'YES': ";
            if (trim(fgets(STDIN)) !== 'YES') {
                log_info("Installation cancelled by user.");
                exit(0);
            }
            $drop_existing = true;
            log_info("Proceeding with full database reset.");
        } elseif ($choice === '2') {
            log_info("Proceeding with safe update (adding missing tables).");
        } else {
            log_info("Installation cancelled by user.");
            exit(0);
        }
    } else {
        log_info("Database file not found. A new one will be created.");
    }

    try {
        log_info("Connecting to database...");
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        log_success("Database connection successful.");

        $schemaFile = __DIR__ . '/complete-schema-full.sql';
        if (!file_exists($schemaFile)) {
            log_error("CRITICAL: Master schema file 'complete-schema-full.sql' not found.");
            exit(1);
        }

        log_info("Reading schema from 'complete-schema-full.sql'...");
        $schema = file_get_contents($schemaFile);
        
        if (!$drop_existing) {
            // In safe mode, we remove all DROP statements to prevent data loss.
            $schema = preg_replace('/^DROP (TABLE|VIEW|TRIGGER|INDEX) IF EXISTS[^;]+;/im', '', $schema);
        }

        // Split schema into individual statements, ignoring comments and empty lines.
        $statements = array_filter(
            array_map('trim', explode(';', $schema)),
            fn($stmt) => !empty($stmt) && !str_starts_with($stmt, '--')
        );

        log_info("Preparing to execute " . count($statements) . " SQL statements...\n");
        
        $summary = ['tables' => 0, 'indexes' => 0, 'triggers' => 0, 'views' => 0, 'inserts' => 0, 'errors' => 0];

        foreach ($statements as $statement) {
            try {
                $db->exec($statement);
                // Log and count what was just done
                if (stripos($statement, 'CREATE TABLE') === 0) {
                    preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $statement, $m);
                    log_success("Table created: " . ($m[1] ?? '...'));
                    $summary['tables']++;
                } elseif (stripos($statement, 'CREATE INDEX') === 0) $summary['indexes']++;
                elseif (stripos($statement, 'CREATE TRIGGER') === 0) $summary['triggers']++;
                elseif (stripos($statement, 'CREATE VIEW') === 0) $summary['views']++;
                elseif (stripos($statement, 'INSERT INTO') === 0) $summary['inserts']++;
            } catch (PDOException $e) {
                // If the object already exists and we are in safe mode, just warn. Otherwise, it's an error.
                if (strpos($e->getMessage(), 'already exists') !== false && !$drop_existing) {
                    preg_match('/(TABLE|INDEX|TRIGGER|VIEW)\s+`?(\w+)`?/i', $statement, $m);
                    log_warning("Skipped (already exists): " . ($m[2] ?? 'object'));
                } else {
                    log_error("Error executing statement: " . $e->getMessage());
                    $summary['errors']++;
                }
            }
        }

        echo "\n";
        log_info("==================================================");
        log_info("  Installation Summary");
        log_info("==================================================");
        log_success("Tables created/verified: " . $summary['tables']);
        log_success("Indexes created/verified: " . $summary['indexes']);
        log_success("Triggers created/verified: " . $summary['triggers']);
        log_success("Views created/verified: " . $summary['views']);
        log_success("Default data rows inserted: " . $summary['inserts']);
        
        if ($summary['errors'] > 0) {
            log_warning("Errors encountered: " . $summary['errors']);
        } else {
            log_success("No errors encountered.");
        }
        
        echo "\n";
        log_success("==================================================");
        log_success("  Database setup is complete!");
        log_success("==================================================");
        echo "\n";
        
        log_info("🚀 Next Steps:");
        echo "  1. Run the live scraper to populate the database:\n";
        echo "     " . Colors::$YELLOW . "php live_scraper.php" . Colors::$RESET . "\n";
        echo "  2. Access the admin dashboard to control the scraper in the future.\n";
        echo "  3. Visit the public dashboard to see the results.\n";

        return true;

    } catch (PDOException $e) {
        log_error("A fatal error occurred: " . $e->getMessage());
        return false;
    }
}

// --- Main Execution Block ---
if (php_sapi_name() === 'cli') {
    if (!checkPDOSQLite()) {
        log_error("SQLite PDO driver is required but not installed. Cannot continue.");
        exit(1);
    }
    $success = setup_database();
    exit($success ? 0 : 1);
} else {
    header('Content-Type: text/plain');
    echo "ERROR: This script is a command-line tool and cannot be run from a web browser.\n\n";
    echo "Please run it from your server's terminal:\n";
    echo "php " . __FILE__ . "\n";
    exit(1);
}