<?php
/**
 * Inmate360 - Inmate Case Detail Scraper
 * Scrapes detailed case information from individual inmate pages
 * Accessible via docket number click-through
 */

require_once 'config.php';

class InmateCaseDetailScraper {
    private $db;
    private $baseUrl = 'https://weba.claytoncountyga.gov';
    private $detailUrlPattern = '/sjiinqcgi-bin/wsj100r.pgm?';
    
    public function __construct() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initializeDatabase();
        } catch (PDOException $e) {
            $this->log("Database initialization error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    private function initializeDatabase() {
        try {
            $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inmate_case_details'");
            if (!$result->fetch()) {
                $this->log("Creating inmate case details schema...");
                $schema = file_get_contents(__DIR__ . '/inmate_case_details_schema.sql');
                $this->db->exec($schema);
            }
        } catch (Exception $e) {
            $this->log("Schema initialization error: " . $e->getMessage(), 'error');
        }
    }
    
    private function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Fetch detailed page for inmate
     */
    private function fetchDetailPage($inmateId, $docketNumber) {
        try {
            // Build URL to fetch inmate detail - using the docket number as parameter
            $url = $this->baseUrl . $this->detailUrlPattern . 'docket=' . urlencode($docketNumber);
            
            $this->log("Fetching detail page for inmate: $inmateId (Docket: $docketNumber)");
            $this->log("URL: $url");
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => TIMEOUT,
                CURLOPT_USERAGENT => USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $this->log("cURL Error fetching detail page: $error", 'error');
                return null;
            }
            
            if ($httpCode !== 200) {
                $this->log("HTTP Error $httpCode fetching detail page", 'warning');
                return null;
            }
            
            if (empty($response)) {
                $this->log("Empty response for detail page", 'warning');
                return null;
            }
            
            $this->log("Successfully fetched detail page (Length: " . strlen($response) . " bytes)");
            return $response;
            
        } catch (Exception $e) {
            $this->log("Error fetching detail page: " . $e->getMessage(), 'error');
            return null;
        }
    }
    
    /**
     * Parse detail page and extract case information
     */
    private function parseDetailPage($html, $inmateId, $docketNumber) {
        try {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            $caseDetails = [
                'inmate_id' => $inmateId,
                'docket_number' => $docketNumber,
                'charges' => [],
                'court_dates' => [],
                'bonds' => [],
                'disposition' => null,
                'sentence' => null,
                'probation_status' => null,
                'scrape_time' => date('Y-m-d H:i:s')
            ];
            
            // Extract all text content for fallback parsing
            $bodyText = $xpath->query('//body')->item(0)->textContent;
            $bodyText = preg_replace('/\s+/', ' ', $bodyText);
            
            // Parse Charges
            $chargePattern = '/(?:CHARGE|OFFENSE)(?:\s+|:)([^:\n]+?)(?=CHARGE|OFFENSE|DISPOSITION|BOND|$)/is';
            if (preg_match_all($chargePattern, $bodyText, $matches)) {
                foreach ($matches[1] as $charge) {
                    $charge = trim($charge);
                    if (!empty($charge) && strlen($charge) > 3) {
                        $caseDetails['charges'][] = [
                            'description' => $charge,
                            'type' => $this->determineChargeType($charge),
                            'parsed_date' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
            
            // Parse Bond Information
            if (preg_match('/BOND\s*(?:AMOUNT)?[:=\s]+\$?([0-9,\.]+)/i', $bodyText, $matches)) {
                $caseDetails['bonds'][] = [
                    'amount' => str_replace(',', '', $matches[1]),
                    'type' => $this->extractBondType($bodyText),
                    'status' => $this->extractBondStatus($bodyText)
                ];
            }
            
            // Parse Disposition
            if (preg_match('/DISPOSITION[:=\s]+([^:\n]+?)(?=SENTENCE|PROBATION|COURT|$)/i', $bodyText, $matches)) {
                $caseDetails['disposition'] = trim($matches[1]);
            }
            
            // Parse Sentence
            if (preg_match('/SENTENCE[:=\s]+([^:\n]+?)(?=PROBATION|RELEASE|$)/i', $bodyText, $matches)) {
                $caseDetails['sentence'] = trim($matches[1]);
            }
            
            // Parse Probation Status
            if (preg_match('/PROBATION(?:\s+STATUS)?[:=\s]+([^:\n]+?)(?=COURT|APPEAL|$)/i', $bodyText, $matches)) {
                $caseDetails['probation_status'] = trim($matches[1]);
            }
            
            // Parse Court Dates - look for date patterns
            $datePattern = '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/';
            if (preg_match_all($datePattern, $bodyText, $matches)) {
                foreach ($matches[1] as $date) {
                    $parsedDate = $this->parseDate($date);
                    if ($parsedDate) {
                        $caseDetails['court_dates'][] = [
                            'date' => $parsedDate,
                            'raw' => $date
                        ];
                    }
                }
            }
            
            // Parse from table rows if available
            $rows = $xpath->query('//table//tr');
            foreach ($rows as $row) {
                $cells = $xpath->query('.//td|.//th', $row);
                if ($cells->length >= 2) {
                    $rowText = '';
                    foreach ($cells as $cell) {
                        $rowText .= trim($cell->textContent) . ' | ';
                    }
                    
                    // Extract additional info from rows
                    $this->parseTableRow($rowText, $caseDetails);
                }
            }
            
            $this->log("Parsed " . count($caseDetails['charges']) . " charges from detail page");
            $this->log("Parsed " . count($caseDetails['court_dates']) . " court dates");
            
            return $caseDetails;
            
        } catch (Exception $e) {
            $this->log("Error parsing detail page: " . $e->getMessage(), 'error');
            return null;
        }
    }
    
    /**
     * Parse individual table row for additional details
     */
    private function parseTableRow($rowText, &$caseDetails) {
        if (preg_match('/CHARGE.*?([^|]+)/i', $rowText, $matches)) {
            $charge = trim($matches[1]);
            if (!empty($charge) && strlen($charge) > 3) {
                $caseDetails['charges'][] = [
                    'description' => $charge,
                    'type' => $this->determineChargeType($charge),
                    'parsed_date' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        if (preg_match('/COURT.*?(\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4})/i', $rowText, $matches)) {
            $parsedDate = $this->parseDate($matches[1]);
            if ($parsedDate) {
                $caseDetails['court_dates'][] = [
                    'date' => $parsedDate,
                    'raw' => $matches[1]
                ];
            }
        }
    }
    
    /**
     * Determine charge type (Felony, Misdemeanor, etc.)
     */
    private function determineChargeType($charge) {
        $charge = strtoupper($charge);
        
        $felonyKeywords = [
            'FELONY', 'MURDER', 'ROBBERY', 'BURGLARY', 'AGGRAVATED', 'AGG ',
            'RAPE', 'KIDNAPPING', 'ARSON', 'TRAFFICKING', 'POSSESSION WITH INTENT',
            'ARMED ROBBERY', 'HOME INVASION', 'CHILD MOLESTATION', 'WEAPON',
            'FIREARM', 'DISTRIBUTION', 'SEXUAL BATTERY', 'ASSAULT WITH'
        ];
        
        $misdemeanorKeywords = [
            'MISDEMEANOR', 'MISD', 'BATTERY', 'SIMPLE', 'DISORDERLY',
            'TRESPASS', 'THEFT BY TAKING', 'SHOPLIFTING', 'DUI', 'DRIVING',
            'LICENSE', 'SUSPENDED', 'STOP SIGN', 'YIELD SIGN'
        ];
        
        foreach ($felonyKeywords as $keyword) {
            if (strpos($charge, $keyword) !== false) {
                return 'Felony';
            }
        }
        
        foreach ($misdemeanorKeywords as $keyword) {
            if (strpos($charge, $keyword) !== false) {
                return 'Misdemeanor';
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Extract bond type from text
     */
    private function extractBondType($text) {
        if (preg_match('/BOND\s+TYPE[:=\s]+([^:\n]+)/i', $text, $matches)) {
            return trim($matches[1]);
        }
        if (stripos($text, 'cash') !== false) return 'Cash';
        if (stripos($text, 'property') !== false) return 'Property';
        if (stripos($text, 'recognizance') !== false) return 'Own Recognizance';
        return 'Unknown';
    }
    
    /**
     * Extract bond status from text
     */
    private function extractBondStatus($text) {
        if (stripos($text, 'not ready') !== false) return 'Not Ready';
        if (stripos($text, 'ready') !== false) return 'Ready';
        if (stripos($text, 'forfeited') !== false) return 'Forfeited';
        if (stripos($text, 'released') !== false) return 'Released';
        return 'Pending';
    }
    
    /**
     * Parse date string to YYYY-MM-DD format
     */
    private function parseDate($dateStr) {
        $dateStr = trim($dateStr);
        if (empty($dateStr)) return null;
        
        $formats = ['m/d/Y', 'm-d-Y', 'Y-m-d', 'd/m/Y'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }
        
        return null;
    }
    
    /**
     * Save case details to database
     */
    private function saveCaseDetails($caseDetails) {
        try {
            $this->db->beginTransaction();
            
            // Check if record already exists
            $stmt = $this->db->prepare("
                SELECT id FROM inmate_case_details 
                WHERE inmate_id = ? AND docket_number = ?
            ");
            $stmt->execute([$caseDetails['inmate_id'], $caseDetails['docket_number']]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing record
                $stmt = $this->db->prepare("
                    UPDATE inmate_case_details SET
                        disposition = ?,
                        sentence = ?,
                        probation_status = ?,
                        charges_json = ?,
                        court_dates_json = ?,
                        bonds_json = ?,
                        last_updated = CURRENT_TIMESTAMP
                    WHERE inmate_id = ? AND docket_number = ?
                ");
                $stmt->execute([
                    $caseDetails['disposition'],
                    $caseDetails['sentence'],
                    $caseDetails['probation_status'],
                    json_encode($caseDetails['charges']),
                    json_encode($caseDetails['court_dates']),
                    json_encode($caseDetails['bonds']),
                    $caseDetails['inmate_id'],
                    $caseDetails['docket_number']
                ]);
                $this->log("Updated case details for inmate: " . $caseDetails['inmate_id']);
            } else {
                // Insert new record
                $stmt = $this->db->prepare("
                    INSERT INTO inmate_case_details (
                        inmate_id, docket_number, disposition, sentence,
                        probation_status, charges_json, court_dates_json,
                        bonds_json, scrape_time
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $caseDetails['inmate_id'],
                    $caseDetails['docket_number'],
                    $caseDetails['disposition'],
                    $caseDetails['sentence'],
                    $caseDetails['probation_status'],
                    json_encode($caseDetails['charges']),
                    json_encode($caseDetails['court_dates']),
                    json_encode($caseDetails['bonds']),
                    $caseDetails['scrape_time']
                ]);
                $this->log("Saved new case details for inmate: " . $caseDetails['inmate_id']);
            }
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            $this->log("Error saving case details: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Scrape details for a single inmate
     */
    public function scrapeInmateDetails($inmateId, $docketNumber) {
        try {
            $this->log("Starting detail scrape for inmate: $inmateId");
            
            // Fetch detail page
            $html = $this->fetchDetailPage($inmateId, $docketNumber);
            if (!$html) {
                $this->log("Failed to fetch detail page for $inmateId", 'warning');
                return false;
            }
            
            // Parse details
            $caseDetails = $this->parseDetailPage($html, $inmateId, $docketNumber);
            if (!$caseDetails) {
                $this->log("Failed to parse detail page for $inmateId", 'warning');
                return false;
            }
            
            // Save to database
            $saved = $this->saveCaseDetails($caseDetails);
            if (!$saved) {
                $this->log("Failed to save case details for $inmateId", 'warning');
                return false;
            }
            
            $this->log("Successfully completed detail scrape for inmate: $inmateId");
            return true;
            
        } catch (Exception $e) {
            $this->log("Error scraping inmate details: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Scrape details for all inmates in database
     */
    public function scrapeAllInmateDetails() {
        try {
            $this->log("========================================");
            $this->log("Starting detail scrape for all inmates");
            $this->log("========================================");
            
            $stmt = $this->db->query("
                SELECT id, inmate_id FROM inmates 
                WHERE in_jail = 1
                ORDER BY booking_date DESC
            ");
            
            $inmates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($inmates);
            $successful = 0;
            $failed = 0;
            
            $this->log("Found $total inmates to scrape details for");
            
            foreach ($inmates as $index => $inmate) {
                $progress = ($index + 1) . " / $total";
                
                if ($this->scrapeInmateDetails($inmate['inmate_id'], $inmate['inmate_id'])) {
                    $successful++;
                } else {
                    $failed++;
                }
                
                // Be polite - wait between requests
                if ($index < $total - 1) {
                    usleep(500000); // 0.5 second delay
                }
            }
            
            $this->log("========================================");
            $this->log("Detail scrape completed!");
            $this->log("Successful: $successful");
            $this->log("Failed: $failed");
            $this->log("========================================");
            
            return ['successful' => $successful, 'failed' => $failed, 'total' => $total];
            
        } catch (Exception $e) {
            $this->log("Error scraping all inmate details: " . $e->getMessage(), 'error');
            return ['successful' => 0, 'failed' => 0, 'total' => 0];
        }
    }
    
    /**
     * Get cached case details for an inmate
     */
    public function getCaseDetails($inmateId, $docketNumber) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM inmate_case_details 
                WHERE inmate_id = ? AND docket_number = ?
                LIMIT 1
            ");
            $stmt->execute([$inmateId, $docketNumber]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log("Error retrieving case details: " . $e->getMessage(), 'error');
            return null;
        }
    }
}

// CLI Usage
if (php_sapi_name() === 'cli') {
    $scraper = new InmateCaseDetailScraper();
    
    if (isset($argv[1])) {
        if ($argv[1] === '--all') {
            $scraper->scrapeAllInmateDetails();
        } elseif ($argv[1] === '--inmate' && isset($argv[2])) {
            $inmateId = $argv[2];
            $docketNumber = $argv[3] ?? $inmateId;
            $scraper->scrapeInmateDetails($inmateId, $docketNumber);
        } else {
            echo "Usage: php scrape_inmate_details.php [--all|--inmate <id> <docket>]\n";
        }
    } else {
        echo "Usage: php scrape_inmate_details.php [--all|--inmate <id> <docket>]\n";
    }
}
?>