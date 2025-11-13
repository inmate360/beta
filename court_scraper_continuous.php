<?php
/**
 * Continuous Court Scraper - Saves progress every 60 seconds
 * Run this script continuously in the background
 * Usage: php court_scraper_continuous.php
 */

require_once 'config.php';
require_once 'court_scraper.php';

class ContinuousCourtScraper {
    private $db;
    private $scraper;
    private $logFile;
    private $progressFile;
    private $saveInterval = 60; // Save progress every 60 seconds
    private $lastSaveTime;

    public function __construct() {
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->scraper = new CourtScraper(true);
        $this->logFile = __DIR__ . '/court_scraper_continuous.log';
        $this->progressFile = __DIR__ . '/court_scraper_progress.json';
        $this->lastSaveTime = time();

        $this->log("Continuous Court Scraper Started");
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

    private function loadProgress() {
        if (file_exists($this->progressFile)) {
            $json = file_get_contents($this->progressFile);
            return json_decode($json, true);
        }
        return [
            'current_offset' => 0,
            'total_processed' => 0,
            'total_cases_found' => 0,
            'total_cases_saved' => 0,
            'last_inmate_id' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }

    private function saveProgress($progress) {
        $progress['last_updated'] = date('Y-m-d H:i:s');
        file_put_contents($this->progressFile, json_encode($progress, JSON_PRETTY_PRINT));
        $this->log("Progress saved: {$progress['total_processed']} inmates processed, {$progress['total_cases_saved']} cases saved");
    }

    public function run() {
        $progress = $this->loadProgress();
        $this->log("Resuming from offset {$progress['current_offset']}");

        $batchSize = 10; // Process 10 inmates at a time
        $totalInmates = $this->db->query("SELECT COUNT(*) FROM inmates WHERE name IS NOT NULL")->fetchColumn();

        $this->log("Total inmates to process: " . $totalInmates);

        while ($progress['current_offset'] < $totalInmates) {
            try {
                // Fetch next batch
                $stmt = $this->db->prepare("
                    SELECT inmate_id, name 
                    FROM inmates 
                    WHERE name IS NOT NULL 
                    ORDER BY id
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$batchSize, $progress['current_offset']]);
                $inmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($inmates)) {
                    $this->log("No more inmates to process. Scraping complete!");
                    break;
                }

                foreach ($inmates as $inmate) {
                    $startTime = time();

                    // Parse name
                    $nameParts = explode(',', $inmate['name']);
                    $lastName = trim($nameParts[0]);
                    $firstName = isset($nameParts[1]) ? trim($nameParts[1]) : '';

                    $this->log("Processing [{$progress['total_processed']}/{$totalInmates}]: {$inmate['name']}");

                    // Search court records
                    $result = $this->scraper->searchByName($lastName, $firstName);

                    if ($result['success']) {
                        $progress['total_cases_found'] += $result['cases_found'];
                        $progress['total_cases_saved'] += $result['cases_saved'];
                        $this->log("  Found {$result['cases_found']} cases, saved {$result['cases_saved']}");
                    } else {
                        $this->log("  Error: {$result['error']}");
                    }

                    $progress['total_processed']++;
                    $progress['current_offset']++;
                    $progress['last_inmate_id'] = $inmate['inmate_id'];

                    // Save progress every 60 seconds
                    if (time() - $this->lastSaveTime >= $this->saveInterval) {
                        $this->saveProgress($progress);
                        $this->lastSaveTime = time();
                    }

                    // Be polite - wait 2 seconds between requests
                    sleep(2);
                }

                // Save progress after each batch
                $this->saveProgress($progress);

            } catch (Exception $e) {
                $this->log("ERROR: " . $e->getMessage());
                $this->saveProgress($progress);
                sleep(5); // Wait before retrying
            }
        }

        // Final save
        $this->saveProgress($progress);

        $this->log("===== SCRAPING COMPLETE =====");
        $this->log("Total Inmates Processed: {$progress['total_processed']}");
        $this->log("Total Cases Found: {$progress['total_cases_found']}");
        $this->log("Total Cases Saved: {$progress['total_cases_saved']}");
        $this->log("Started: {$progress['started_at']}");
        $this->log("Completed: " . date('Y-m-d H:i:s'));

        return $progress;
    }

    public function getStatus() {
        $progress = $this->loadProgress();
        $totalInmates = $this->db->query("SELECT COUNT(*) FROM inmates WHERE name IS NOT NULL")->fetchColumn();

        $percentComplete = $totalInmates > 0 ? round(($progress['total_processed'] / $totalInmates) * 100, 2) : 0;

        return [
            'status' => $progress['current_offset'] >= $totalInmates ? 'completed' : 'running',
            'progress' => $progress,
            'total_inmates' => $totalInmates,
            'percent_complete' => $percentComplete,
            'estimated_remaining' => $totalInmates - $progress['total_processed']
        ];
    }

    public function resetProgress() {
        if (file_exists($this->progressFile)) {
            unlink($this->progressFile);
        }
        $this->log("Progress reset");
    }
}

// CLI handling
if (php_sapi_name() === 'cli') {
    $command = isset($argv[1]) ? $argv[1] : 'run';

    $scraper = new ContinuousCourtScraper();

    switch ($command) {
        case 'run':
            echo "Starting continuous court scraper...\n";
            echo "Progress will be saved every 60 seconds.\n";
            echo "Press Ctrl+C to stop (progress will be saved).\n\n";

            // Handle Ctrl+C gracefully
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGINT, function() use ($scraper) {
                    echo "\n\nReceived stop signal. Saving progress...\n";
                    exit(0);
                });
            }

            $scraper->run();
            break;

        case 'status':
            $status = $scraper->getStatus();
            echo "=== Court Scraper Status ===\n";
            echo "Status: " . $status['status'] . "\n";
            echo "Progress: {$status['progress']['total_processed']} / {$status['total_inmates']} ({$status['percent_complete']}%)\n";
            echo "Cases Found: {$status['progress']['total_cases_found']}\n";
            echo "Cases Saved: {$status['progress']['total_cases_saved']}\n";
            echo "Last Updated: {$status['progress']['last_updated']}\n";
            break;

        case 'reset':
            $scraper->resetProgress();
            echo "Progress reset. Run 'php court_scraper_continuous.php run' to start fresh.\n";
            break;

        default:
            echo "Court Scraper - Continuous Mode\n";
            echo "Usage:\n";
            echo "  php court_scraper_continuous.php run     - Start/resume scraping\n";
            echo "  php court_scraper_continuous.php status  - Check current status\n";
            echo "  php court_scraper_continuous.php reset   - Reset progress\n";
    }
} else {
    // Web interface
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? 'status';
    $scraper = new ContinuousCourtScraper();

    switch ($action) {
        case 'status':
            echo json_encode($scraper->getStatus());
            break;

        case 'start':
            // Start in background
            $command = "php " . __FILE__ . " run > /dev/null 2>&1 &";
            exec($command);
            echo json_encode(['success' => true, 'message' => 'Scraper started in background']);
            break;

        case 'reset':
            $scraper->resetProgress();
            echo json_encode(['success' => true, 'message' => 'Progress reset']);
            break;

        default:
            echo json_encode(['error' => 'Invalid action']);
    }
}
?>