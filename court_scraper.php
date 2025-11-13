<?php
/**
 * Inmate360 - Clayton County Court Case Scraper
 * Uses pagination logic and writes to the main application database.
 * Debug mode is available for troubleshooting.
 *
 * Extended with a searchByName() method so callers can query the court site
 * by defendant last name and first name (the court site requires at least a last name).
 */

require_once 'config.php';

class CourtScraper {
    private $db;
    private $baseUrl;
    private $retryCount = 0;
    private $debugInfo = [];
    private $debugEnabled = true;

    public function __construct($debug = false, $baseUrl = null) {
        // Use the main application database
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Use the base URL from config.php for the main scrape
        $this->baseUrl = $baseUrl ?? COURT_SCRAPE_BASE_URL;
        $this->debugEnabled = $debug;

        // Initialize database schema if needed
        $this->initializeDatabase();

        if ($this->debugEnabled) {
            $this->addDebugInfo('Court Scraper initialized', 'Database: ' . DB_PATH . ' BaseURL: ' . $this->baseUrl);
        }
    }

    /**
     * Initialize database schema if tables don't exist in the main database
     */
    private function initializeDatabase() {
        try {
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='court_cases'");
            if (!$result->fetch()) {
                $this->addDebugInfo('Database initialization', 'Court schema not found. Creating tables...');
                
                $schemaPath = __DIR__ . '/court_schema.sql';
                if (file_exists($schemaPath)) {
                    $schema = file_get_contents($schemaPath);
                    $this->db->exec($schema);
                    $this->addDebugInfo('Database initialization', 'Court schema loaded successfully into jail_data.db');
                } else {
                    throw new Exception('court_schema.sql not found');
                }
            } else {
                 $this->addDebugInfo('Database initialization', 'Court schema already exists.');
            }
        } catch (Exception $e) {
            $this->addDebugInfo('Database initialization error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add debug information
     */
    private function addDebugInfo($title, $message) {
        if (!$this->debugEnabled) return;
        
        // Ensure message is a string
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

    /**
     * Save debug information to HTML file
     */
    private function saveDebugPage() {
        if (!$this->debugEnabled) return;

        $debugFile = __DIR__ . '/court_debug.html';
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Court Scraper Debug</title>
    <style>
        body { font-family: monospace; background: #0f0f23; color: #e0e0e0; padding: 20px; }
        .debug-entry { background: #1e1e3f; padding: 15px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #00d4ff; }
        h1 { color: #00d4ff; }
        pre { white-space: pre-wrap; }
    </style>
</head>
<body><h1>Inmate360 Court Scraper Debug Log</h1>';

        foreach (array_reverse($this->debugInfo) as $entry) {
            $html .= '<div class="debug-entry"><strong>' . htmlspecialchars($entry['timestamp']) . ' - ' . htmlspecialchars($entry['title']) . '</strong><br><pre>' . htmlspecialchars($entry['message']) . '</pre></div>';
        }

        $html .= '</body></html>';
        file_put_contents($debugFile, $html);
    }
    
    /**
     * Main scrape function (full site / pagination)
     */
    public function scrapeAll() {
        $totalCases = 0;
        $startTime = time();
        $pageCount = 0;
        $visitedUrls = [];

        $this->addDebugInfo('Scrape started', 'Starting full scrape from: ' . $this->baseUrl);
        echo "Starting Court Scrape...\n";

        $currentUrl = $this->baseUrl;

        while ($currentUrl && $pageCount < 1000) { // Safety limit for pages
            if (in_array($currentUrl, $visitedUrls)) {
                $this->addDebugInfo('Pagination Loop Detected', 'URL: ' . $currentUrl);
                echo "Pagination loop detected. Stopping.\n";
                break;
            }
            $visitedUrls[] = $currentUrl;

            $pageCount++;
            echo "--- Scraping Page $pageCount: $currentUrl ---\n";

            $pageData = $this->scrapePage($currentUrl);
            if ($pageData === false) {
                $this->addDebugInfo('Page scrape failed', 'URL: ' . $currentUrl);
                echo "Failed to scrape page, stopping.\n";
                break;
            }
            
            $casesFound = count($pageData['cases']);
            if ($casesFound === 0) {
                echo "No cases found on this page. Stopping.\n";
                break;
            }

            $totalCases += $casesFound;
            echo "Found $casesFound cases on this page.\n";

            foreach ($pageData['cases'] as $caseData) {
                $this->saveCase($caseData);
            }

            $currentUrl = $pageData['next_url'];
            if ($currentUrl) {
                usleep(500000); // 0.5s delay
            }
        }

        $duration = time() - $startTime;
        $this->logScrape($totalCases, 'success', "Scraped $totalCases cases from $pageCount pages.");
        echo "Scrape complete. Total cases: $totalCases. Duration: $duration seconds.\n";
        return $totalCases;
    }

    /**
     * Scrape a single page with retry logic
     */
    private function scrapePage($url) {
        $this->retryCount = 0;
        while ($this->retryCount < MAX_RETRIES) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
            curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $html) {
                if ($this->debugEnabled) {
                    file_put_contents(__DIR__ . '/court_debug_page.html', $html);
                }
                
                libxml_use_internal_errors(true);
                $doc = new DOMDocument();
                $doc->loadHTML($html);
                libxml_clear_errors();
                $xpath = new DOMXPath($doc);

                $cases = $this->parseCases($xpath);
                $nextUrl = $this->findNextPageUrl($xpath, $url);

                return ['cases' => $cases, 'next_url' => $nextUrl];
            }

            $this->retryCount++;
            $delay = RETRY_DELAY * pow(2, $this->retryCount - 1); // Exponential backoff
            $this->addDebugInfo('HTTP Request Failed (Retry ' . $this->retryCount . ')', "URL: $url, HTTP Code: $httpCode, cURL Error: $curlError. Retrying in $delay seconds...");
            sleep($delay);
        }

        $this->addDebugInfo('HTTP Request Failed Permanently', "URL: $url, Max retries reached.");
        return false;
    }

    /**
     * Parse cases from the page using DOMXPath
     */
    private function parseCases($xpath) {
        $cases = [];
        // The table rows seem to be direct children of the body in some cases.
        $rows = $xpath->query('//tr');
        
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 5) continue;

            $data = [];
            foreach ($cells as $cell) {
                $data[] = trim($cell->nodeValue);
            }

            // Based on observed structure
            $caseData = [
                'case_type' => $data[0] ?? null,
                'defendant_name' => $data[1] ?? null,
                'offense' => $data[2] ?? null,
                'filing_date' => isset($data[3]) ? $this->formatDate($data[3]) : null,
                'case_number' => $data[4] ?? null,
                'court' => $data[5] ?? null,
                'judge' => $data[6] ?? null,
                'case_status' => null, // Not consistently available in the table
            ];

            if (!empty($caseData['case_number']) && !empty($caseData['defendant_name'])) {
                $cases[] = $caseData;
            }
        }
        return $cases;
    }

    /**
     * Find the URL for the next page
     */
    private function findNextPageUrl($xpath, $baseUrl) {
        $patterns = [
            "//a[contains(translate(., 'NEXT', 'next'), 'next')]",
            "//a[contains(text(), 'Next')]",
            "//a[contains(text(), 'NEXT')]",
            "//a[./img[@alt='Next Page']]", // For image-based links
            "//input[@type='submit' and contains(@value, 'Next')]/@onclick", // For javascript links in onclick
        ];

        foreach ($patterns as $pattern) {
            $links = $xpath->query($pattern);
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                if (empty($href)) {
                     // Try to extract from onclick if it's a JS link
                    $onclick = $link->getAttribute('onclick');
                    if (preg_match("/window\.location\.href='([^']+)'/", $onclick, $matches)) {
                        $href = $matches[1];
                    }
                }
                
                $text = trim($link->textContent);
                if (!empty($href)) {
                    $url = $this->resolveUrl($href, $baseUrl);
                    $this->addDebugInfo('Pagination Found', "Next URL: $url");
                    return $url;
                }
            }
        }

	        $this->addDebugInfo('Pagination', "No 'Next' link found with any pattern.");
	        return null;
	    }
	
	    /**
	     * Log scrape result to the database
	     */
	    private function logScrape($casesFound, $status, $message) {
	        try {
	            $stmt = $this->db->prepare("
	                INSERT INTO court_scrape_logs (scrape_time, cases_found, status, message)
	                VALUES (CURRENT_TIMESTAMP, ?, ?, ?)
	            ");
	            $stmt->execute([$casesFound, $status, $message]);
	        } catch (Exception $e) {
	            $this->addDebugInfo('Scrape Log Error', $e->getMessage());
	        }
	    }
    
    /**
     * Resolve relative URLs to absolute
     */
    private function resolveUrl($url, $baseUrl) {
        if (strpos($url, 'http') === 0) {
            return $url; // Already absolute
        }
        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'http';
        $host = $parsedBase['host'] ?? '';
        
        if (strpos($url, '/') === 0) {
            return "$scheme://$host$url";
        }
        
        // Handle relative path from current directory
        $path = dirname($parsedBase['path'] ?? '');
        return "$scheme://$host" . ($path === '/' ? '' : $path) . "/$url";
    }

    /**
     * Format date to YYYY-MM-DD
     */
    private function formatDate($dateStr) {
        $date = DateTime::createFromFormat('m/d/Y', trim($dateStr));
        return $date ? $date->format('Y-m-d') : null;
    }

    /**
     * Save case to the database
     */
    private function saveCase($data) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM court_cases WHERE case_number = ?");
            $stmt->execute([$data['case_number']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $this->db->prepare("UPDATE court_cases SET defendant_name = ?, offense = ?, filing_date = ?, judge = ?, court = ?, case_type = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$data['defendant_name'], $data['offense'], $data['filing_date'], $data['judge'], $data['court'], $data['case_type'], $existing['id']]);
            } else {
                $parts = explode('-', $data['case_number']);
                $year = $parts[0] ?? 0;
                $seq = $parts[2] ?? 0;
                
                $stmt = $this->db->prepare("INSERT INTO court_cases (case_number, case_year, case_sequence, defendant_name, offense, filing_date, judge, court, case_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$data['case_number'], $year, $seq, $data['defendant_name'], $data['offense'], $data['filing_date'], $data['judge'], $data['court'], $data['case_type']]);
            }
        } catch (PDOException $e) {
            $this->addDebugInfo('Database save error', $e->getMessage());
            echo "DB Error: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Log scrape activity
     */
    private function logScrape($casesFound, $status, $message) {
        try {
            $stmt = $this->db->prepare("INSERT INTO court_scrape_logs (cases_found, status, message, source_url) VALUES (?, ?, ?, ?)");
            $stmt->execute([$casesFound, $status, $message, $this->baseUrl]);
        } catch (Exception $e) {
             $this->addDebugInfo('Log error', $e->getMessage());
        }
    }

    // --- NEW: Search by name (used by AJAX endpoint) ---
    /**
     * Search court site by first and last name.
     * Court site requires last name; first name is optional but helps narrow results.
     *
     * Returns an array of parsed case arrays:
     * [
     *   ['case_number'=>..., 'defendant_name'=>..., 'offense'=>..., 'filing_date'=>..., 'court'=>..., 'judge'=>...],
     *   ...
     * ]
     */
	    public function searchByName($firstName, $lastName) {
	        $first = trim($firstName);
	        $last = trim($lastName);
	
	        if (empty($last)) {
	            throw new InvalidArgumentException('Last name is required to search court records.');
	        }
	
	        // Build search URL (observed pattern)
	        $params = [
	            'rtype' => 'E',
	            'dvt'   => 'C',
	            'ctt'   => 'A',
	            'lname' => $last,
	            'fname' => $first
	        ];
	        $url = 'https://weba.claytoncountyga.gov/casinqcgi-bin/wci011r.pgm?' . http_build_query($params); // Use the direct search URL
	
	        $this->addDebugInfo('SearchByName URL', $url);
	
	        $this->retryCount = 0;
	        while ($this->retryCount < MAX_RETRIES) {
	            // Fetch page
	            $html = $this->fetchRaw($url);
	            
	            if ($html !== false) {
	                // Parse results
	                $results = $this->parseSearchResults($html);
	
	                // Save debug page if enabled
	                if ($this->debugEnabled) {
	                    file_put_contents(__DIR__ . '/court_search_page.html', $html);
	                }
	
	                return $results;
	            }
	
	            $this->retryCount++;
	            $delay = RETRY_DELAY * pow(2, $this->retryCount - 1); // Exponential backoff
	            $this->addDebugInfo('Ad-hoc Search Failed (Retry ' . $this->retryCount . ')', "URL: $url. Retrying in $delay seconds...");
	            sleep($delay);
	        }
	
	        throw new Exception("Failed to get search results after " . MAX_RETRIES . " retries.");
	    }

    /**
     * Fetch raw HTML (small helper for searchByName)
     */
    private function fetchRaw($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
        curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->addDebugInfo('cURL error', $err);
            return false;
        }

        if ($httpCode !== 200 || !$html) {
            $this->addDebugInfo('HTTP error', "Code: $httpCode");
            return false;
        }

        return $html;
    }

    /**
     * Parse the court search results HTML for name-based search pages.
     * The court search results are typically tables with rows representing cases.
     */
    private function parseSearchResults($html) {
        $cases = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Attempt to find the main results table heuristically
        $tables = $xpath->query('//table');
        foreach ($tables as $table) {
            $rows = $xpath->query('.//tr', $table);
            if ($rows->length < 2) continue; // not likely a results table

            // Check first data row for plausible case number pattern
            $firstDataRow = null;
            foreach ($rows as $r) {
                $th = $xpath->query('.//th', $r);
                if ($th->length > 0) continue; // header
                $tds = $xpath->query('.//td', $r);
                if ($tds->length >= 3) {
                    $firstDataRow = $r;
                    break;
                }
            }
            if (!$firstDataRow) continue;

            // Parse rows in this table
            foreach ($rows as $row) {
                $tds = $xpath->query('.//td', $row);
                if ($tds->length < 3) continue;

                $cells = [];
                foreach ($tds as $td) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
                }

                // Heuristic mapping: many court results have columns like:
                // Case Number | Defendant Name | Charge/Offense | Filing Date | Judge ...
                // We'll try to find a case number token among the cells
                $caseNumber = null;
                $defendantName = null;
                $offense = null;
                $filingDate = null;
                $judge = null;
                $court = null;

                // Try to find a token that looks like a case number
                foreach ($cells as $c) {
                    if (preg_match('/\d{4}[-\/]\d+[-\/]?\d*/', $c) || preg_match('/[A-Z]{1,3}\s*\d{2,6}/', $c)) {
                        $caseNumber = $c;
                        break;
                    }
                }

                // Fallback assignments based on number of columns
                if (count($cells) >= 4) {
                    // try typical ordering
                    $caseNumber = $caseNumber ?? $cells[0];
                    $defendantName = $cells[1] ?? $cells[0];
                    $offense = $cells[2] ?? '';
                    $filingDate = $cells[3] ?? '';
                    $judge = $cells[4] ?? null;
                } else {
                    // minimal
                    $defendantName = $cells[0] ?? '';
                    $offense = $cells[1] ?? '';
                    $caseNumber = $cells[2] ?? $caseNumber;
                }

                // Normalize filing date if possible
                $filingDateNorm = null;
                if (!empty($filingDate)) {
                    $d = DateTime::createFromFormat('m/d/Y', $filingDate);
                    if ($d) $filingDateNorm = $d->format('Y-m-d');
                }

                $case = [
                    'case_number' => $caseNumber ? $caseNumber : null,
                    'defendant_name' => $defendantName,
                    'offense' => $offense,
                    'filing_date' => $filingDateNorm ?: null,
                    'judge' => $judge,
                    'court' => $court
                ];

                // Only include reasonably complete entries
                if (!empty($case['defendant_name']) && !empty($case['offense'])) {
                    $cases[] = $case;
                }
            }

            if (!empty($cases)) break; // stop after first plausible table
        }

        // As a fallback, try to parse any lines that look like "Case #: ... Defendant: ..."
        if (empty($cases)) {
            // Extract textual lines
            $text = strip_tags($html);
            $lines = preg_split('/\r?\n/', $text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/Case.*?(?::|\s)\s*([A-Z0-9\-\_\/]+)/i', $line, $m)) {
                    $cases[] = [
                        'case_number' => $m[1],
                        'defendant_name' => null,
                        'offense' => null,
                        'filing_date' => null,
                        'judge' => null,
                        'court' => null
                    ];
                }
            }
        }

        return $cases;
    }

}

// Run the scraper if executed directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    $debug = in_array('--debug', $argv);
    $scraper = new CourtScraper($debug);
    
    try {
        $scraper->scrapeAll();
    } catch (Exception $e) {
        // Log the fatal error to the database
        $scraper->logScrape(0, 'error', 'Fatal error during scrape: ' . $e->getMessage());
        echo "Fatal error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
