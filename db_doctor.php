<?php
/**
 * Inmate360 - Database Doctor
 * Analyzes log files for schema errors (missing tables/columns) and attempts to repair them.
 */

if (!defined('DB_PATH')) {
    require_once 'config.php';
}

class DatabaseDoctor {
    private $db;
    private $logFile;
    private $repairs = [];

    public function __construct() {
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->logFile = defined('LOG_FILE') ? LOG_FILE : __DIR__ . '/scraper.log';
    }

    /**
     * Main function to analyze logs and apply fixes.
     * @return array A report of actions taken.
     */
    public function analyzeAndRepair() {
        $report = [
            'found_issues' => 0,
            'successful_fixes' => 0,
            'failed_fixes' => 0,
            'messages' => []
        ];

        if (!file_exists($this->logFile)) {
            $report['messages'][] = "Log file not found at: {$this->logFile}";
            return $report;
        }

        $logContent = file_get_contents($this->logFile);
        $this->findMissingColumns($logContent);
        $this->findMissingTables($logContent);

        $report['found_issues'] = count($this->repairs);

        if (empty($this->repairs)) {
            $report['messages'][] = "No schema errors found in the log file. Database appears healthy.";
            return $report;
        }

        foreach ($this->repairs as $repair) {
            try {
                $this->db->exec($repair['sql']);
                $report['messages'][] = "✅ SUCCESS: " . $repair['message'];
                $report['successful_fixes']++;
            } catch (Exception $e) {
                $errorMessage = "❌ FAILED to apply fix: " . $repair['message'] . " | Error: " . $e->getMessage();
                $report['messages'][] = $errorMessage;
                $report['failed_fixes']++;
                error_log("[db_doctor] " . $errorMessage);
            }
        }
        
        return $report;
    }

    /**
     * Finds "no such column" errors in the log.
     */
    private function findMissingColumns($logContent) {
        // Pattern: table `charges` has no column named `docket_number`
        preg_match_all('/table\s+`?(\w+)`?\s+has no column named\s+`?(\w+)`?/i', $logContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = $match[1];
            $column = $match[2];
            $key = "{$table}.{$column}";

            // Avoid duplicate repair attempts
            if (!isset($this->repairs[$key])) {
                $this->repairs[$key] = [
                    'sql' => "ALTER TABLE `{$table}` ADD COLUMN `{$column}` TEXT",
                    'message' => "Found missing column '{$column}' in table '{$table}'. Added it."
                ];
            }
        }
    }
    
    /**
     * Finds "no such table" errors in the log.
     */
    private function findMissingTables($logContent) {
        // This is more complex as it requires knowing the table schema.
        // For now, we will add a placeholder and recommend a full install.
        // A simple CREATE TABLE is often not enough without all columns.
        preg_match_all('/no such table:\s+`?(\w+)`?/i', $logContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = $match[1];
            $key = "table.{$table}";

            if (!isset($this->repairs[$key])) {
                $this->repairs[$key] = [
                    'sql' => "-- Cannot auto-create table '{$table}'. Schema is unknown.",
                    'message' => "Found reference to missing table '{$table}'. Automatic repair is not supported. Please run the full installation script (install.php)."
                ];
            }
        }
    }
}