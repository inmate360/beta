<?php
/**
 * Fix Missing Tables and Columns Script
 * Reads log files to identify missing tables/columns and creates them
 */

require_once __DIR__ . '/config.php';

class TableFixer {
    private $db;
    private $missingTables = [];
    private $missingColumns = [];
    private $errors = [];
    private $fixed = [];
    private $fixedColumns = [];
    
    // Define all table schemas used in the application
    public $tableSchemas = [
        'inmates' => "
            CREATE TABLE IF NOT EXISTS inmates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inmate_id TEXT UNIQUE NOT NULL,
                docket_number TEXT,
                first_name TEXT,
                last_name TEXT,
                name TEXT,
                age INTEGER,
                sex TEXT,
                race TEXT,
                height TEXT,
                weight TEXT,
                booking_date TEXT,
                release_date TEXT,
                bond_amount TEXT,
                le_number TEXT,
                in_jail INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        
        'charges' => "
            CREATE TABLE IF NOT EXISTS charges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inmate_id TEXT NOT NULL,
                charge_description TEXT,
                charge_type TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (inmate_id) REFERENCES inmates(inmate_id)
            )",
        
        'scrape_logs' => "
            CREATE TABLE IF NOT EXISTS scrape_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT,
                inmates_found INTEGER,
                message TEXT
            )",
        
        'inmate_detail_urls' => "
            CREATE TABLE IF NOT EXISTS inmate_detail_urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inmate_id TEXT UNIQUE NOT NULL,
                detail_url TEXT NOT NULL,
                scraped INTEGER DEFAULT 0,
                scrape_attempts INTEGER DEFAULT 0,
                last_scrape_attempt DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        
        'inmate_case_details' => "
            CREATE TABLE IF NOT EXISTS inmate_case_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inmate_id TEXT NOT NULL,
                docket_number TEXT,
                disposition TEXT,
                sentence TEXT,
                charges_json TEXT,
                court_dates_json TEXT,
                bonds_json TEXT,
                scrape_time DATETIME,
                last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(inmate_id, docket_number)
            )",
        
        'court_cases' => "
            CREATE TABLE IF NOT EXISTS court_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_number TEXT UNIQUE NOT NULL,
                case_year TEXT,
                case_sequence TEXT,
                defendant_name TEXT,
                offense TEXT,
                filing_date DATE,
                disposition TEXT,
                sentence TEXT,
                judge TEXT,
                court TEXT,
                bond_amount REAL,
                active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        
        'court_charges' => "
            CREATE TABLE IF NOT EXISTS court_charges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_number TEXT NOT NULL,
                charge_description TEXT,
                charge_type TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (case_number) REFERENCES court_cases(case_number)
            )",
        
        'court_scrape_logs' => "
            CREATE TABLE IF NOT EXISTS court_scrape_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT,
                cases_found INTEGER,
                message TEXT
            )",
        
        'inmate_court_cases' => "
            CREATE TABLE IF NOT EXISTS inmate_court_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                inmate_id TEXT NOT NULL,
                case_number TEXT NOT NULL,
                confidence_score REAL,
                matched_on TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(inmate_id, case_number),
                FOREIGN KEY (inmate_id) REFERENCES inmates(inmate_id),
                FOREIGN KEY (case_number) REFERENCES court_cases(case_number)
            )",
        
        'system_access_log' => "
            CREATE TABLE IF NOT EXISTS system_access_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                access_type TEXT,
                user_email TEXT,
                ip_address TEXT,
                user_agent TEXT,
                access_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        
        'invite_codes' => "
            CREATE TABLE IF NOT EXISTS invite_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                email TEXT NOT NULL,
                used INTEGER DEFAULT 0,
                used_at DATETIME,
                used_by_ip TEXT,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT
            )",
        
        'system_stats' => "
            CREATE TABLE IF NOT EXISTS system_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stat_date DATE,
                total_inmates INTEGER,
                active_inmates INTEGER,
                released_inmates INTEGER,
                total_charges INTEGER,
                total_searches INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(stat_date)
            )",
        
        'court_stats' => "
            CREATE TABLE IF NOT EXISTS court_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stat_date DATE,
                total_cases INTEGER,
                active_cases INTEGER,
                total_court_charges INTEGER,
                linked_cases INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(stat_date)
            )"
    ];
    
    // Define expected columns for each table
    public $tableColumns = [
        'inmates' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'inmate_id' => 'TEXT',
            'docket_number' => 'TEXT',
            'first_name' => 'TEXT',
            'last_name' => 'TEXT',
            'name' => 'TEXT',
            'age' => 'INTEGER',
            'sex' => 'TEXT',
            'race' => 'TEXT',
            'height' => 'TEXT',
            'weight' => 'TEXT',
            'booking_date' => 'TEXT',
            'release_date' => 'TEXT',
            'bond_amount' => 'TEXT',
            'le_number' => 'TEXT',
            'in_jail' => 'INTEGER DEFAULT 1',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'charges' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'inmate_id' => 'TEXT',
            'charge_description' => 'TEXT',
            'charge_type' => 'TEXT',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'scrape_logs' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'scrape_time' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'status' => 'TEXT',
            'inmates_found' => 'INTEGER',
            'message' => 'TEXT'
        ],
        'inmate_detail_urls' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'inmate_id' => 'TEXT',
            'detail_url' => 'TEXT',
            'scraped' => 'INTEGER DEFAULT 0',
            'scrape_attempts' => 'INTEGER DEFAULT 0',
            'last_scrape_attempt' => 'DATETIME',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'inmate_case_details' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'inmate_id' => 'TEXT',
            'docket_number' => 'TEXT',
            'disposition' => 'TEXT',
            'sentence' => 'TEXT',
            'charges_json' => 'TEXT',
            'court_dates_json' => 'TEXT',
            'bonds_json' => 'TEXT',
            'scrape_time' => 'DATETIME',
            'last_updated' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'court_cases' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'case_number' => 'TEXT',
            'case_year' => 'TEXT',
            'case_sequence' => 'TEXT',
            'defendant_name' => 'TEXT',
            'offense' => 'TEXT',
            'filing_date' => 'DATE',
            'disposition' => 'TEXT',
            'sentence' => 'TEXT',
            'judge' => 'TEXT',
            'court' => 'TEXT',
            'bond_amount' => 'REAL',
            'active' => 'INTEGER DEFAULT 1',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'court_charges' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'case_number' => 'TEXT',
            'charge_description' => 'TEXT',
            'charge_type' => 'TEXT',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'court_scrape_logs' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'scrape_time' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'status' => 'TEXT',
            'cases_found' => 'INTEGER',
            'message' => 'TEXT'
        ],
        'inmate_court_cases' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'inmate_id' => 'TEXT',
            'case_number' => 'TEXT',
            'confidence_score' => 'REAL',
            'matched_on' => 'TEXT',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'system_access_log' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'access_type' => 'TEXT',
            'user_email' => 'TEXT',
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
            'access_time' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'invite_codes' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'code' => 'TEXT',
            'email' => 'TEXT',
            'used' => 'INTEGER DEFAULT 0',
            'used_at' => 'DATETIME',
            'used_by_ip' => 'TEXT',
            'expires_at' => 'DATETIME',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'created_by' => 'TEXT'
        ],
        'system_stats' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'stat_date' => 'DATE',
            'total_inmates' => 'INTEGER',
            'active_inmates' => 'INTEGER',
            'released_inmates' => 'INTEGER',
            'total_charges' => 'INTEGER',
            'total_searches' => 'INTEGER',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ],
        'court_stats' => [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'stat_date' => 'DATE',
            'total_cases' => 'INTEGER',
            'active_cases' => 'INTEGER',
            'total_court_charges' => 'INTEGER',
            'linked_cases' => 'INTEGER',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ]
    ];
    
    public function __construct() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get table schemas (public method for external access)
     */
    public function getTableSchemas() {
        return $this->tableSchemas;
    }
    
    /**
     * Get table columns (public method for external access)
     */
    public function getTableColumns() {
        return $this->tableColumns;
    }
    
    /**
     * Scan log files for missing table/column errors
     */
    public function scanLogs() {
        $logFiles = [
            defined('LOG_FILE') ? LOG_FILE : 'scraper.log',
            'court_scraper.log',
            'error.log',
            'php_error.log'
        ];
        
        // Patterns for missing tables
        $tablePatterns = [
            '/no such table:\s*(\w+)/i',
            '/table\s+(\w+)\s+doesn\'t exist/i',
            '/SQLSTATE\[HY000\].*?table.*?(\w+)/i',
            '/Base table or view not found.*?\'(\w+)\'/i'
        ];
        
        // Patterns for missing columns
        $columnPatterns = [
            '/no such column:\s*(\w+)\.(\w+)/i',
            '/Unknown column\s+[\'"]?(\w+)[\'"]?\s+in\s+[\'"]?(\w+)[\'"]?/i',
            '/table\s+(\w+)\s+has no column named\s+(\w+)/i',
            '/column\s+[\'"]?(\w+)[\'"]?\s+does not exist/i'
        ];
        
        foreach ($logFiles as $logFile) {
            if (!file_exists($logFile)) continue;
            
            $content = file_get_contents($logFile);
            
            // Find missing tables
            foreach ($tablePatterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $table) {
                        $this->missingTables[$table] = true;
                    }
                }
            }
            
            // Find missing columns
            foreach ($columnPatterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    for ($i = 0; $i < count($matches[0]); $i++) {
                        if (isset($matches[2][$i])) {
                            $table = $matches[1][$i];
                            $column = $matches[2][$i];
                        } else {
                            $column = $matches[1][$i];
                            $table = 'unknown';
                        }
                        if (!isset($this->missingColumns[$table])) {
                            $this->missingColumns[$table] = [];
                        }
                        $this->missingColumns[$table][$column] = true;
                    }
                }
            }
        }
        
        return [
            'tables' => array_keys($this->missingTables),
            'columns' => $this->missingColumns
        ];
    }
    
    /**
     * Check which tables actually exist in database
     */
    public function checkExistingTables() {
        $existing = [];
        try {
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'");
            $existing = $result->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->errors[] = "Error checking tables: " . $e->getMessage();
        }
        return $existing;
    }
    
    /**
     * Get columns for a specific table
     */
    public function getTableColumnsFromDB($tableName) {
        $columns = [];
        try {
            $result = $this->db->query("PRAGMA table_info('$tableName')");
            $info = $result->fetchAll(PDO::FETCH_ASSOC);
            foreach ($info as $col) {
                $columns[] = $col['name'];
            }
        } catch (Exception $e) {
            // Table doesn't exist
        }
        return $columns;
    }
    
    /**
     * Check missing columns for all tables
     */
    public function checkMissingColumns() {
        $missingColumns = [];
        $existingTables = $this->checkExistingTables();
        
        foreach ($existingTables as $table) {
            if (isset($this->tableColumns[$table])) {
                $expectedColumns = array_keys($this->tableColumns[$table]);
                $actualColumns = $this->getTableColumnsFromDB($table);
                $missing = array_diff($expectedColumns, $actualColumns);
                
                if (!empty($missing)) {
                    $missingColumns[$table] = $missing;
                }
            }
        }
        
        return $missingColumns;
    }
    
    /**
     * Add missing columns to existing tables
     */
    public function addMissingColumns($columnsToAdd = null) {
        if ($columnsToAdd === null) {
            $columnsToAdd = $this->checkMissingColumns();
        }
        
        foreach ($columnsToAdd as $table => $columns) {
            foreach ($columns as $column) {
                if (isset($this->tableColumns[$table][$column])) {
                    $columnDef = $this->tableColumns[$table][$column];
                    
                    // Skip primary key columns
                    if (stripos($columnDef, 'PRIMARY KEY') !== false) {
                        continue;
                    }
                    
                    try {
                        $sql = "ALTER TABLE $table ADD COLUMN $column $columnDef";
                        $this->db->exec($sql);
                        $this->fixedColumns[] = "$table.$column";
                    } catch (Exception $e) {
                        $this->errors[] = "Failed to add column '$column' to table '$table': " . $e->getMessage();
                    }
                }
            }
        }
        
        return $this->fixedColumns;
    }
    
    /**
     * Create missing tables
     */
    public function createMissingTables($tablesToCreate = []) {
        if (empty($tablesToCreate)) {
            // Create all tables that don't exist
            $existing = $this->checkExistingTables();
            $tablesToCreate = array_diff(array_keys($this->tableSchemas), $existing);
        }
        
        foreach ($tablesToCreate as $tableName) {
            if (isset($this->tableSchemas[$tableName])) {
                try {
                    $this->db->exec($this->tableSchemas[$tableName]);
                    $this->fixed[] = $tableName;
                } catch (Exception $e) {
                    $this->errors[] = "Failed to create table '$tableName': " . $e->getMessage();
                }
            }
        }
        
        return $this->fixed;
    }
    
    /**
     * Add missing indexes for performance
     */
    public function addIndexes() {
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_inmates_inmate_id ON inmates(inmate_id)",
            "CREATE INDEX IF NOT EXISTS idx_inmates_docket ON inmates(docket_number)",
            "CREATE INDEX IF NOT EXISTS idx_charges_inmate_id ON charges(inmate_id)",
            "CREATE INDEX IF NOT EXISTS idx_court_cases_case_number ON court_cases(case_number)",
            "CREATE INDEX IF NOT EXISTS idx_court_cases_defendant ON court_cases(defendant_name)",
            "CREATE INDEX IF NOT EXISTS idx_inmate_court_cases_inmate ON inmate_court_cases(inmate_id)",
            "CREATE INDEX IF NOT EXISTS idx_inmate_court_cases_case ON inmate_court_cases(case_number)",
            "CREATE INDEX IF NOT EXISTS idx_scrape_logs_time ON scrape_logs(scrape_time)",
            "CREATE INDEX IF NOT EXISTS idx_court_scrape_logs_time ON court_scrape_logs(scrape_time)"
        ];
        
        $indexesAdded = 0;
        foreach ($indexes as $index) {
            try {
                $this->db->exec($index);
                $indexesAdded++;
            } catch (Exception $e) {
                // Index might already exist, ignore error
            }
        }
        
        return $indexesAdded;
    }
    
    /**
     * Fix all issues (tables, columns, indexes)
     */
    public function fixAll() {
        // Create missing tables
        $this->createMissingTables();
        
        // Add missing columns
        $this->addMissingColumns();
        
        // Add indexes
        $indexesAdded = $this->addIndexes();
        
        return [
            'tables_created' => $this->fixed,
            'columns_added' => $this->fixedColumns,
            'indexes_added' => $indexesAdded,
            'errors' => $this->errors
        ];
    }
    
    public function getReport() {
        return [
            'missing_from_logs' => array_keys($this->missingTables),
            'missing_columns_from_logs' => $this->missingColumns,
            'fixed_tables' => $this->fixed,
            'fixed_columns' => $this->fixedColumns,
            'errors' => $this->errors,
            'existing' => $this->checkExistingTables()
        ];
    }
}

// Run if called directly
if (php_sapi_name() === 'cli' || (isset($_GET['action']) && $_GET['action'] === 'fix_tables')) {
    $fixer = new TableFixer();
    
    echo "Database Structure Fixer - " . date('Y-m-d H:i:s') . "\n";
    echo "=" . str_repeat("=", 50) . "\n\n";
    
    echo "Scanning log files for missing tables/columns...\n";
    $issues = $fixer->scanLogs();
    
    echo "Found " . count($issues['tables']) . " potentially missing tables in logs.\n";
    echo "Found " . count($issues['columns']) . " tables with potentially missing columns.\n\n";
    
    echo "Checking actual database structure...\n";
    $missingColumns = $fixer->checkMissingColumns();
    
    echo "Fixing all issues...\n";
    $result = $fixer->fixAll();
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "REPORT:\n";
    echo str_repeat("=", 50) . "\n\n";
    
    if (!empty($result['tables_created'])) {
        echo "✅ Created tables:\n";
        foreach ($result['tables_created'] as $table) {
            echo "   - $table\n";
        }
        echo "\n";
    }
    
    if (!empty($result['columns_added'])) {
        echo "✅ Added columns:\n";
        foreach ($result['columns_added'] as $col) {
            echo "   - $col\n";
        }
        echo "\n";
    }
    
    if ($result['indexes_added'] > 0) {
        echo "✅ Added/verified " . $result['indexes_added'] . " indexes\n\n";
    }
    
    if (!empty($result['errors'])) {
        echo "❌ Errors:\n";
        foreach ($result['errors'] as $error) {
            echo "   - $error\n";
        }
        echo "\n";
    }
    
    $report = $fixer->getReport();
    echo "Total tables now: " . count($report['existing']) . "\n";
}