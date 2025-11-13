<?php
require_once 'config.php';

class InmateDetailsScraper {
    private $db;
    private $baseUrl = 'https://weba.claytoncountyga.gov';
    
    public function __construct() {
        $this->initDatabase();
    }
    
    private function initDatabase() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->log("Database connection established");
        } catch (PDOException $e) {
            $this->log("Database error: " . $e->getMessage(), 'error');
            die("Database connection failed\n");
        }
    }
    
    private function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    private function fetchPage($url) {
        $this->log("Fetching detail page: $url");
        
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
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        if (empty($response)) {
            throw new Exception("Empty response received");
        }
        
        $this->log("Successfully fetched detail page (Length: " . strlen($response) . " bytes)");
        return $response;
    }
    
    private function parseInmateDetails($html, $inmateId) {
        $details = [
            'inmate_id' => $inmateId,
            'sex' => '',
            'race' => '',
            'height' => '',
            'weight' => '',
            'hair_color' => '',
            'eye_color' => '',
            'arresting_agency' => '',
            'booking_officer' => '',
            'facility_location' => '',
            'classification' => ''
        ];
        
        // Create DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        try {
            // Try to find detail information in various formats (adjust based on actual HTML structure)
            
            // Look for tables containing details
            $tables = $xpath->query('//table');
            foreach ($tables as $table) {
                $rows = $xpath->query('.//tr', $table);
                foreach ($rows as $row) {
                    $cells = $xpath->query('.//td', $row);
                    if ($cells->length >= 2) {
                        $label = trim($cells->item(0)->textContent);
                        $value = trim($cells->item(1)->textContent);
                        
                        // Map labels to details
                        $label = strtolower($label);
                        
                        if (strpos($label, 'sex') !== false || strpos($label, 'gender') !== false) {
                            $details['sex'] = $value;
                        } elseif (strpos($label, 'race') !== false) {
                            $details['race'] = $value;
                        } elseif (strpos($label, 'height') !== false) {
                            $details['height'] = $value;
                        } elseif (strpos($label, 'weight') !== false) {
                            $details['weight'] = $value;
                        } elseif (strpos($label, 'hair') !== false) {
                            $details['hair_color'] = $value;
                        } elseif (strpos($label, 'eye') !== false) {
                            $details['eye_color'] = $value;
                        } elseif (strpos($label, 'agency') !== false || strpos($label, 'arresting') !== false) {
                            $details['arresting_agency'] = $value;
                        } elseif (strpos($label, 'officer') !== false) {
                            $details['booking_officer'] = $value;
                        } elseif (strpos($label, 'facility') !== false || strpos($label, 'location') !== false) {
                            $details['facility_location'] = $value;
                        } elseif (strpos($label, 'classification') !== false) {
                            $details['classification'] = $value;
                        }
                    }
                }
            }
            
            // Also look for information in divs with labels
            $divs = $xpath->query('//div[@class or @id]');
            foreach ($divs as $div) {
                $text = trim($div->textContent);
                $class = $div->getAttribute('class');
                $id = $div->getAttribute('id');
                
                if (preg_match('/sex|gender/i', $class . ' ' . $id . ' ' . $text)) {
                    preg_match('/:\s*(.+?)(?:\n|$)/i', $text, $matches);
                    if (isset($matches[1])) {
                        $details['sex'] = trim($matches[1]);
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->log("Error parsing details for inmate $inmateId: " . $e->getMessage(), 'warning');
        }
        
        return $details;
    }
    
    private function updateInmateDetails($details) {
        try {
            $stmt = $this->db->prepare("
                UPDATE inmates 
                SET sex = ?, race = ?, height = ?, weight = ?, 
                    hair_color = ?, eye_color = ?, arresting_agency = ?, 
                    booking_officer = ?, facility_location = ?, classification = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE inmate_id = ?
            ");
            
            $stmt->execute([
                $details['sex'],
                $details['race'],
                $details['height'],
                $details['weight'],
                $details['hair_color'],
                $details['eye_color'],
                $details['arresting_agency'],
                $details['booking_officer'],
                $details['facility_location'],
                $details['classification'],
                $details['inmate_id']
            ]);
            
            return true;
        } catch (Exception $e) {
            $this->log("Error updating inmate details: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    private function markUrlAsScraped($inmateId, $success = true) {
        try {
            $stmt = $this->db->prepare("
                UPDATE inmate_detail_urls 
                SET scraped = ?, scrape_attempts = scrape_attempts + 1, 
                    last_scrape_attempt = CURRENT_TIMESTAMP
                WHERE inmate_id = ?
            ");
            $stmt->execute([$success ? 1 : 0, $inmateId]);
        } catch (Exception $e) {
            $this->log("Error updating URL status: " . $e->getMessage(), 'error');
        }
    }
    
    private function getUnscrapedUrls($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT inmate_id, detail_url 
                FROM inmate_detail_urls 
                WHERE scraped = 0 AND scrape_attempts < 3
                ORDER BY created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log("Error fetching unscraped URLs: " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    public function scrapeDetails($batchSize = 10, $maxAttempts = 5) {
        $this->log("========================================");
        $this->log("Starting inmate details scraper");
        $this->log("Batch size: $batchSize, Max attempts: $maxAttempts");
        $this->log("========================================");
        
        $totalScraped = 0;
        $totalErrors = 0;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->log("\n--- Scrape Attempt $attempt/$maxAttempts ---");
            
            $urls = $this->getUnscrapedUrls($batchSize);
            
            if (empty($urls)) {
                $this->log("No more unscraped URLs found. Details scraping complete.");
                break;
            }
            
            $this->log("Found " . count($urls) . " URLs to scrape");
            
            foreach ($urls as $item) {
                $inmateId = $item['inmate_id'];
                $detailUrl = $item['detail_url'];
                
                try {
                    $this->log("\nProcessing inmate: $inmateId");
                    $this->log("URL: $detailUrl");
                    
                    // Fetch the detail page
                    $html = $this->fetchPage($detailUrl);
                    
                    // Parse the details
                    $details = $this->parseInmateDetails($html, $inmateId);
                    
                    // Update the database
                    if ($this->updateInmateDetails($details)) {
                        $this->log("Successfully updated details for inmate $inmateId");
                        $this->markUrlAsScraped($inmateId, true);
                        $totalScraped++;
                    } else {
                        $this->log("Failed to update details for inmate $inmateId", 'warning');
                        $this->markUrlAsScraped($inmateId, false);
                        $totalErrors++;
                    }
                    
                    // Be polite - wait between requests
                    sleep(1);
                    
                } catch (Exception $e) {
                    $this->log("Error scraping details for $inmateId: " . $e->getMessage(), 'error');
                    $this->markUrlAsScraped($inmateId, false);
                    $totalErrors++;
                    
                    // Wait longer on error
                    sleep(2);
                }
            }
            
            // Wait between batches
            if ($attempt < $maxAttempts) {
                sleep(3);
            }
        }
        
        $this->log("\n========================================");
        $this->log("Details scraping session completed");
        $this->log("Total successfully scraped: $totalScraped");
        $this->log("Total errors: $totalErrors");
        $this->log("========================================\n");
        
        return $totalScraped;
    }
    
    public function getScrapingStatus() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN scraped = 1 THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN scraped = 0 AND scrape_attempts < 3 THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN scrape_attempts >= 3 THEN 1 ELSE 0 END) as failed
                FROM inmate_detail_urls
            ");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->log("Error getting scraping status: " . $e->getMessage(), 'error');
            return null;
        }
    }
}

// Run the details scraper
if (php_sapi_name() === 'cli') {
    $scraper = new InmateDetailsScraper();
    
    // Check status first
    $status = $scraper->getScrapingStatus();
    if ($status) {
        echo "Current Status:\n";
        echo "  Total URLs: {$status['total']}\n";
        echo "  Completed: {$status['completed']}\n";
        echo "  Pending: {$status['pending']}\n";
        echo "  Failed (3+ attempts): {$status['failed']}\n\n";
    }
    
    // Run the scraper
    if (isset($argv[1])) {
        $batchSize = isset($argv[1]) ? (int)$argv[1] : 10;
        $maxAttempts = isset($argv[2]) ? (int)$argv[2] : 5;
        $scraper->scrapeDetails($batchSize, $maxAttempts);
    } else {
        $scraper->scrapeDetails(10, 5);
    }
} else {
    echo "This script must be run from command line.\n";
    echo "Usage: php details_scraper.php [batch_size] [max_attempts]\n";
    echo "Example: php details_scraper.php 10 5\n";
}
?>