<?php
/**
 * Inmate360 Database Setup Script
 * 
 * This script sets up all required tables for the platform:
 * - Invite system tables
 * - Jail data tables
 * - Court data tables
 * - All indexes and triggers
 * 
 * Run this BEFORE running the scraper for the first time
 */

require_once 'config.php';

// Colors for terminal output
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

function log_success($message) {
    log_message("✓ $message", Colors::$GREEN);
}

function log_error($message) {
    log_message("✗ $message", Colors::$RED);
}

function log_info($message) {
    log_message("ℹ $message", Colors::$BLUE);
}

function log_warning($message) {
    log_message("⚠ $message", Colors::$YELLOW);
}

// Main setup function
function setup_database() {
    log_info("==================================================");
    log_info("  Inmate360 Database Setup");
    log_info("  Setting up all platform tables...");
    log_info("==================================================\n");

    // Check if database file exists
    if (file_exists(DB_PATH)) {
        log_warning("Database file already exists: " . DB_PATH);
        echo "Do you want to:\n";
        echo "  1) Drop all tables and recreate (DESTRUCTIVE - loses all data)\n";
        echo "  2) Keep existing tables and add missing ones\n";
        echo "  3) Cancel setup\n";
        echo "Choice (1/2/3): ";
        
        $choice = trim(fgets(STDIN));
        
        if ($choice === '3') {
            log_info("Setup cancelled by user.");
            exit(0);
        }
        
        $drop_existing = ($choice === '1');
    } else {
        log_info("Creating new database file...");
        $drop_existing = false;
    }

    try {
        // Connect to database
        log_info("Connecting to database...");
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        log_success("Database connection established");

        // Read schema file
        $schemaFile = __DIR__ . '/complete-schema.sql';
        if (!file_exists($schemaFile)) {
            log_error("Schema file not found: $schemaFile");
            log_info("Please ensure complete-schema.sql is in the same directory");
            exit(1);
        }

        log_info("Reading schema file...");
        $schema = file_get_contents($schemaFile);
        
        if (!$drop_existing) {
            // Remove DROP statements if we're keeping existing tables
            $schema = preg_replace('/DROP TABLE IF EXISTS[^;]+;/i', '', $schema);
            log_info("Preserving existing tables");
        }

        // Split schema into individual statements
        $statements = array_filter(
            array_map('trim', explode(';', $schema)),
            function($stmt) {
                return !empty($stmt) && 
                       !preg_match('/^--/', $stmt) && 
                       !preg_match('/^\/\*/', $stmt);
            }
        );

        log_info("Executing " . count($statements) . " SQL statements...\n");

        $tables_created = 0;
        $indexes_created = 0;
        $triggers_created = 0;
        $inserts_done = 0;
        $errors = 0;

        foreach ($statements as $i => $statement) {
            $statement = trim($statement);
            if (empty($statement)) continue;

            try {
                // Determine statement type for better logging
                if (stripos($statement, 'CREATE TABLE') !== false) {
                    preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+(\w+)/i', $statement, $matches);
                    $table_name = $matches[1] ?? 'unknown';
                    log_info("Creating table: $table_name");
                    $db->exec($statement);
                    $tables_created++;
                    log_success("Table created: $table_name");
                    
                } elseif (stripos($statement, 'CREATE INDEX') !== false) {
                    preg_match('/CREATE INDEX\s+(\w+)/i', $statement, $matches);
                    $index_name = $matches[1] ?? 'unknown';
                    $db->exec($statement);
                    $indexes_created++;
                    
                } elseif (stripos($statement, 'CREATE TRIGGER') !== false) {
                    preg_match('/CREATE TRIGGER\s+(\w+)/i', $statement, $matches);
                    $trigger_name = $matches[1] ?? 'unknown';
                    log_info("Creating trigger: $trigger_name");
                    $db->exec($statement);
                    $triggers_created++;
                    log_success("Trigger created: $trigger_name");
                    
                } elseif (stripos($statement, 'INSERT INTO') !== false) {
                    preg_match('/INSERT INTO\s+(\w+)/i', $statement, $matches);
                    $table_name = $matches[1] ?? 'unknown';
                    $db->exec($statement);
                    $inserts_done++;
                    
                } elseif (stripos($statement, 'PRAGMA') !== false || 
                         stripos($statement, 'SELECT') !== false) {
                    // Verification queries - just execute silently
                    $db->exec($statement);
                } else {
                    // Other statements
                    $db->exec($statement);
                }
                
            } catch (PDOException $e) {
                // Check if error is because object already exists
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    log_warning("Skipped (already exists): " . substr($statement, 0, 50) . "...");
                } else {
                    log_error("Error executing statement: " . $e->getMessage());
                    log_error("Statement: " . substr($statement, 0, 100) . "...");
                    $errors++;
                }
            }
        }

        echo "\n";
        log_info("==================================================");
        log_info("  Setup Summary");
        log_info("==================================================");
        log_success("Tables created: $tables_created");
        log_success("Indexes created: $indexes_created");
        log_success("Triggers created: $triggers_created");
        log_success("Data rows inserted: $inserts_done");
        
        if ($errors > 0) {
            log_warning("Errors encountered: $errors");
        }

        // Verify database setup
        echo "\n";
        log_info("Verifying database setup...");
        
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        log_success("Total tables in database: " . count($tables));
        
        echo "\nTables:\n";
        foreach ($tables as $table) {
            echo "  - $table\n";
        }

        // Check critical tables
        $required_tables = [
            'invite_codes',
            'invite_usage_log',
            'beta_users',
            'inmates',
            'charges',
            'scrape_logs'
        ];

        $missing_tables = array_diff($required_tables, $tables);
        
        if (empty($missing_tables)) {
            log_success("\nAll required tables are present!");
        } else {
            log_error("\nMissing required tables: " . implode(', ', $missing_tables));
            exit(1);
        }

        // Verify inmates table structure
        echo "\n";
        log_info("Verifying inmates table structure...");
        $columns = $db->query("PRAGMA table_info(inmates)")->fetchAll(PDO::FETCH_ASSOC);
        
        $required_columns = ['docket_number', 'inmate_id', 'name', 'first_name', 'last_name', 
                            'age', 'le_number', 'booking_date', 'release_date', 'bond_amount', 'in_jail'];
        
        $existing_columns = array_column($columns, 'name');
        $missing_columns = array_diff($required_columns, $existing_columns);
        
        if (empty($missing_columns)) {
            log_success("All required columns present in inmates table");
        } else {
            log_error("Missing columns in inmates table: " . implode(', ', $missing_columns));
            exit(1);
        }

        // Count existing data
        echo "\n";
        log_info("Checking existing data...");
        $data_counts = [
            'invite_codes' => $db->query("SELECT COUNT(*) FROM invite_codes")->fetchColumn(),
            'inmates' => $db->query("SELECT COUNT(*) FROM inmates")->fetchColumn(),
            'charges' => $db->query("SELECT COUNT(*) FROM charges")->fetchColumn(),
            'scrape_logs' => $db->query("SELECT COUNT(*) FROM scrape_logs")->fetchColumn()
        ];

        foreach ($data_counts as $table => $count) {
            if ($count > 0) {
                log_info("$table: $count records");
            }
        }

        echo "\n";
        log_success("==================================================");
        log_success("  Database setup completed successfully!");
        log_success("==================================================");
        echo "\n";
        
        log_info("You can now run:");
        echo "  • Scraper: php scraper.php --once\n";
        echo "  • Dashboard: Visit your index.php in browser\n";
        echo "  • Beta Access: Visit beta_access.php\n";
        
        return true;

    } catch (PDOException $e) {
        log_error("Database setup failed: " . $e->getMessage());
        return false;
    }
}

// Run setup
if (php_sapi_name() === 'cli') {
    $success = setup_database();
    exit($success ? 0 : 1);
} else {
    // If accessed via web browser
    header('Content-Type: text/plain');
    echo "This script must be run from the command line.\n\n";
    echo "Usage:\n";
    echo "  php setup.php\n";
    exit(1);
}
?>