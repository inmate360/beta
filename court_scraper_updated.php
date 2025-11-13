<?php
/**
 * Inmate360 - Clayton County Court Case Scraper
 * Updated with HTTP retry logic and robust error handling
 */

require_once 'config.php';

class CourtScraper {
    private $db;
    private $baseUrl;
    private $debugInfo = [];
    private $debugEnabled = true;
    private $maxRetries = 3;
    private $retryDelay = 2; // seconds

    public function __construct($debug = false) {
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->baseUrl = 'https://weba.claytoncountyga.gov/casinqcgi-bin/wci011r.pgm';
        $this->debugEnabled = $debug;

        $this->initializeDatabase();

        if ($this->debugEnabled) {
            $this->addDebugInfo('Court Scraper initialized', 'Database: ' . DB_PATH);
        }
    }

    private function initializeDatabase() {
        try {
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='court_cases'");
            if (!$result->fetch()) {
                $this->addDebugInfo('Database initialization', 'Court schema not found. Creating tables...');

                $schemaPath = __DIR__ . '/court_schema.sql';
                if (file_exists($schemaPath)) {
                    $schema = file_get_contents($schemaPath);
                    $this->db->exec($schema);
                    $this->addDebugInfo('Database initialization', 'Court schema loaded successfully');
                } else {
                    throw new Exception('court_schema.sql not found');
                }
            }
        } catch (Exception $e) {
            $this->addDebugInfo('Database initialization error', $e->getMessage());
            throw $e;
        }
    }

    private function addDebugInfo($title, $message) {
        if (!$this->debugEnabled) return;

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $this->debugInfo[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'title' => (string) $title,
            'message' => (string) $message
        ];
        $this->saveDebugPage();
    }

    private function saveDebugPage() {
        if (!$this->debugEnabled) return;

        $debugFile = __DIR__ . '/court_debug.html';
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Court Scraper Debug</title>
    <style>
        body { font-family: monospace; background: #0f0f23; color: #e0e0e0; padding: 20px; }
        .debug-entry { margin-bottom: 20px; border-left: 3px solid #00cc00; padding-left: 10px; }
        .debug-title { color: #00ff00; font-weight: bold; }
        .debug-time { color: #888; font-size: 0.9em; }
        .debug-message { white-space: pre-wrap; margin-top: 5px; }
    </style>
</head>
<body>
    <h1>Court Scraper Debug Log</h1>';

        foreach ($this->debugInfo as $entry) {
            $html .= '<div class="debug-entry">
                <div class="debug-time">' . htmlspecialchars($entry['timestamp']) . '</div>
                <div class="debug-title">' . htmlspecialchars($entry['title']) . '</div>
                <div class="debug-message">' . htmlspecialchars($entry['message']) . '</div>
            </div>';
        }

        $html .= '</body></html>';
        file_put_contents($debugFile, $html);
    }

    /**
     * Fetch page with retry logic and error handling
     */
    private function fetchPageWithRetry($url, $postData = null, $attempt = 1) {
        try {
            $this->addDebugInfo("HTTP Request (Attempt $attempt)", "URL: $url");

            $ch = curl_init();

            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
            ];

            if ($postData !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
                $this->addDebugInfo("POST Data", $postData);
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            // Handle cURL errors
            if ($errno !== 0) {
                throw new Exception("cURL Error ($errno): $error");
            }

            // Handle HTTP errors with retry
            if ($httpCode >= 500 && $httpCode < 600) {
                throw new Exception("Server Error: HTTP $httpCode");
            } elseif ($httpCode === 429) {
                throw new Exception("Rate Limited: HTTP 429");
            } elseif ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 404) {
                throw new Exception("Client Error: HTTP $httpCode");
            } elseif ($httpCode !== 200) {
                $this->addDebugInfo("HTTP Warning", "Unexpected HTTP code: $httpCode");
            }

            // Validate response
            if (empty($response)) {
                throw new Exception("Empty response received");
            }

            $this->addDebugInfo("HTTP Success", "Response length: " . strlen($response) . " bytes, HTTP $httpCode");
            return $response;

        } catch (Exception $e) {
            $this->addDebugInfo("HTTP Error (Attempt $attempt)", $e->getMessage());

            // Retry logic
            if ($attempt < $this->maxRetries) {
                $delay = $this->retryDelay * $attempt;
                $this->addDebugInfo("Retry", "Waiting {$delay}s before retry...");
                sleep($delay);
                return $this->fetchPageWithRetry($url, $postData, $attempt + 1);
            }

            throw new Exception("Failed after {$this->maxRetries} attempts: " . $e->getMessage());
        }
    }

    /**
     * Search by name - REQUIRES last name
     */
    public function searchByName($lastName, $firstName = '') {
        if (empty($lastName)) {
            $this->addDebugInfo('Search Error', 'Last name is required');
            return ['success' => false, 'error' => 'Last name is required'];
        }

        $this->addDebugInfo('Starting Name Search', "Last: $lastName, First: $firstName");

        try {
            $postData = [
                'rtype' => 'I',
                'dvt' => 'C',
                'lname' => strtoupper(trim($lastName)),
                'fname' => strtoupper(trim($firstName)),
                'submit' => 'Search'
            ];

            $html = $this->fetchPageWithRetry($this->baseUrl, $postData);
            $cases = $this->parseSearchResults($html);

            $this->addDebugInfo('Cases Found', count($cases));

            $savedCount = 0;
            foreach ($cases as $case) {
                if ($this->saveCase($case)) {
                    $savedCount++;
                }
            }

            $this->addDebugInfo('Cases Saved', "$savedCount of " . count($cases));

            return [
                'success' => true,
                'cases_found' => count($cases),
                'cases_saved' => $savedCount,
                'cases' => $cases
            ];

        } catch (Exception $e) {
            $this->addDebugInfo('Search Error', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function parseSearchResults($html) {
        $cases = [];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table');

        foreach ($tables as $table) {
            $rows = $xpath->query('.//tr', $table);

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex === 0) continue;

                $cells = $xpath->query('.//td', $row);

                if ($cells->length >= 3) {
                    $caseNumber = trim($cells->item(0)->textContent);
                    $defendant = trim($cells->item(1)->textContent);
                    $caseType = trim($cells->item(2)->textContent);

                    $links = $xpath->query('.//a', $cells->item(0));
                    $detailUrl = '';
                    if ($links->length > 0) {
                        $href = $links->item(0)->getAttribute('href');
                        if (!empty($href)) {
                            if (strpos($href, 'http') !== 0) {
                                $detailUrl = 'https://weba.claytoncountyga.gov/casinqcgi-bin/' . ltrim($href, '/');
                            } else {
                                $detailUrl = $href;
                            }
                        }
                    }

                    if (!empty($caseNumber)) {
                        $cases[] = [
                            'case_number' => $caseNumber,
                            'defendant_name' => $defendant,
                            'case_type' => $caseType,
                            'filing_date' => $cells->length > 3 ? trim($cells->item(3)->textContent) : '',
                            'status' => $cells->length > 4 ? trim($cells->item(4)->textContent) : '',
                            'detail_url' => $detailUrl
                        ];
                    }
                }
            }
        }

        return $cases;
    }

    private function saveCase($caseData) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM court_cases WHERE case_number = ?");
            $stmt->execute([$caseData['case_number']]);

            if ($stmt->fetch()) {
                $stmt = $this->db->prepare("
                    UPDATE court_cases 
                    SET defendant_name = ?, case_type = ?, filing_date = ?, 
                        status = ?, detail_url = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE case_number = ?
                ");
                $stmt->execute([
                    $caseData['defendant_name'],
                    $caseData['case_type'],
                    $caseData['filing_date'],
                    $caseData['status'],
                    $caseData['detail_url'],
                    $caseData['case_number']
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO court_cases 
                    (case_number, defendant_name, case_type, filing_date, status, detail_url)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $caseData['case_number'],
                    $caseData['defendant_name'],
                    $caseData['case_type'],
                    $caseData['filing_date'],
                    $caseData['status'],
                    $caseData['detail_url']
                ]);
            }

            return true;
        } catch (Exception $e) {
            $this->addDebugInfo('Save Error', $e->getMessage());
            return false;
        }
    }

    /**
     * Scrape all inmates from database
     */
    public function scrapeAllInmates($batchSize = 50) {
        $this->addDebugInfo('Batch Scrape', "Starting scrape for ALL inmates (batch size: $batchSize)");

        try {
            // Get total count
            $totalStmt = $this->db->query("SELECT COUNT(*) as count FROM inmates WHERE name IS NOT NULL");
            $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['count'];

            $this->addDebugInfo('Total Inmates', $totalCount);

            $offset = 0;
            $totalCases = 0;
            $processedInmates = 0;

            while ($offset < $totalCount) {
                $stmt = $this->db->prepare("
                    SELECT inmate_id, name 
                    FROM inmates 
                    WHERE name IS NOT NULL 
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$batchSize, $offset]);
                $inmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($inmates)) break;

                foreach ($inmates as $inmate) {
                    $nameParts = explode(',', $inmate['name']);
                    $lastName = trim($nameParts[0]);
                    $firstName = isset($nameParts[1]) ? trim($nameParts[1]) : '';

                    $this->addDebugInfo('Processing', "[$processedInmates/$totalCount] {$inmate['name']}");

                    $result = $this->searchByName($lastName, $firstName);

                    if ($result['success']) {
                        $totalCases += $result['cases_saved'];
                    }

                    $processedInmates++;
                    sleep(2); // Be polite
                }

                $offset += $batchSize;
            }

            $this->addDebugInfo('Batch Complete', "Processed: $processedInmates, Total cases: $totalCases");

            return ['success' => true, 'processed' => $processedInmates, 'total_cases' => $totalCases];

        } catch (Exception $e) {
            $this->addDebugInfo('Batch Error', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    $scraper = new CourtScraper(true);

    if (isset($argv[1]) && $argv[1] === '--all') {
        echo "Starting full database scrape...\n";
        $result = $scraper->scrapeAllInmates();

        if ($result['success']) {
            echo "Complete! Processed {$result['processed']} inmates, saved {$result['total_cases']} cases\n";
        } else {
            echo "Error: {$result['error']}\n";
        }
    } elseif (isset($argv[1])) {
        $lastName = $argv[1];
        $firstName = isset($argv[2]) ? $argv[2] : '';

        echo "Searching for: $lastName, $firstName\n";
        $result = $scraper->searchByName($lastName, $firstName);

        if ($result['success']) {
            echo "Success! Found {$result['cases_found']} cases, saved {$result['cases_saved']}\n";
        } else {
            echo "Error: {$result['error']}\n";
        }
    } else {
        echo "Usage:\n";
        echo "  php court_scraper.php LASTNAME [FIRSTNAME]  - Search single name\n";
        echo "  php court_scraper.php --all                  - Scrape all inmates\n";
    }

    echo "\nCheck court_debug.html for detailed logs\n";
}
?>