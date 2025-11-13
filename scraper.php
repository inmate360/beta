<?php
/**
 * Inmate360 Complete Jail Scraper
 * Compatible with new schema using docket_number as primary key
 * Scrapes Active Inmates + 31 Day + 48 Hour Docket Books
 */

require_once 'config.php';

class JailScraper {
    private $db;
    private $baseUrl = 'https://weba.claytoncountyga.gov';
    
    public function __construct() {
        $this->initDatabase();
    }
    
    private function initDatabase() {
        try {
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->log("Database initialized successfully");
        } catch (PDOException $e) {
            $this->log("Database error: " . $e->getMessage(), 'error');
            die("Database initialization failed\n");
        }
    }
    
    private function log($message, $level = 'info') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    private function fetchPage($url) {
        $this->log("Fetching URL: $url");
        
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
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        $this->log("Successfully fetched page (Length: " . strlen($html) . " bytes)");
        return $html;
    }
    
    private function findNextPageLink($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Look for "NEXT" link
        $links = $xpath->query('//a[contains(text(), "NEXT")]');
        
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!empty($href)) {
                if (strpos($href, 'http') === 0) {
                    return $href;
                } else {
                    $href = ltrim($href, '/');
                    return $this->baseUrl . '/' . $href;
                }
            }
        }
        
        return null;
    }
    
    private function parseInmates($html) {
        $inmates = [];
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Find table
        $table = $xpath->query('//table[@id="myTable"]')->item(0);
        
        if (!$table) {
            $tables = $xpath->query('//table');
            foreach ($tables as $t) {
                $headers = $xpath->query('.//th', $t);
                if ($headers->length >= 5) {
                    $table = $t;
                    break;
                }
            }
        }
        
        if (!$table) {
            $this->log("No suitable table found", 'error');
            return $inmates;
        }
        
        $rows = $xpath->query('.//tr', $table);
        $this->log("Found {$rows->length} total rows");
        
        // Detect table type
        $headerRow = $xpath->query('.//tr[1]', $table)->item(0);
        $headerCells = $xpath->query('.//th', $headerRow);
        
        $isActiveInmates = false;
        $isReleasedInmates = false;
        
        foreach ($headerCells as $header) {
            $headerText = trim($header->textContent);
            if (stripos($headerText, 'LE#') !== false) {
                $isActiveInmates = true;
                break;
            }
            if (stripos($headerText, 'Released') !== false || stripos($headerText, 'Intake') !== false) {
                $isReleasedInmates = true;
                break;
            }
        }
        
        $this->log("Table type: " . ($isActiveInmates ? "ACTIVE INMATES" : ($isReleasedInmates ? "RELEASED INMATES" : "UNKNOWN")));
        
        $headerFound = false;
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            
            if (!$headerFound) {
                $ths = $xpath->query('.//th', $row);
                if ($ths->length > 0) {
                    $headerFound = true;
                    continue;
                }
            }
            
            if ($isActiveInmates && $cells->length >= 6) {
                // Active Inmates: Docket, Name, LE#, Age, Charge, Bond
                try {
                    $docketCell = $cells->item(0);
                    $docketLink = $xpath->query('.//a', $docketCell)->item(0);
                    $docketNumber = $docketLink ? trim($docketLink->textContent) : trim($docketCell->textContent);
                    
                    $detailUrl = null;
                    if ($docketLink) {
                        $href = $docketLink->getAttribute('href');
                        if (!empty($href)) {
                            $detailUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . '/' . ltrim($href, '/');
                        }
                    }
                    
                    $name = trim($cells->item(1)->textContent);
                    $leNumber = trim($cells->item(2)->textContent);
                    $age = trim($cells->item(3)->textContent);
                    $charge = trim($cells->item(4)->textContent);
                    $bondRaw = trim($cells->item(5)->textContent);
                    $bondRaw = preg_replace('/\s+/', ' ', $bondRaw);
                    
                    // Parse bond info
                    $bondStatus = '';
                    if (stripos($bondRaw, 'NOT READY') !== false) {
                        $bondStatus = 'NOT READY';
                    } elseif (stripos($bondRaw, 'READY') !== false) {
                        $bondStatus = 'READY';
                    }
                    
                    $bondAmount = '';
                    $bondType = '';
                    if (preg_match('/Cash:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $bondType = 'Cash';
                        $bondAmount = '$' . $matches[1];
                    } elseif (preg_match('/Property:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $bondType = 'Property';
                        $bondAmount = '$' . $matches[1];
                    } elseif (stripos($bondRaw, 'No Amount Set') !== false) {
                        $bondAmount = 'No Amount Set';
                    }
                    
                    $fees = '';
                    if (preg_match('/Fees:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $fees = '$' . $matches[1];
                    }
                    
                    $fullBondInfo = $bondStatus;
                    if ($bondAmount) {
                        $fullBondInfo .= ($fullBondInfo ? ' | ' : '') . ($bondType ? $bondType . ': ' : '') . $bondAmount;
                    }
                    if ($fees) {
                        $fullBondInfo .= ' + Fees: ' . $fees;
                    }
                    
                    if (empty($name) || strlen($name) < 3) continue;
                    if (empty($docketNumber) || strlen($docketNumber) < 3) continue;
                    
                    $inmate = [
                        'docket_number' => $docketNumber,
                        'name' => $name,
                        'le_number' => $leNumber,
                        'age' => $age,
                        'booking_date' => null,
                        'released_date' => 'ACTIVE',
                        'charge' => $charge,
                        'bond_info' => $fullBondInfo,
                        'in_jail' => true,
                        'detail_url' => $detailUrl
                    ];
                    
                    $inmates[] = $inmate;
                    $this->log("Parsed (ACTIVE): {$name} (Docket: {$docketNumber}, LE#: {$leNumber})");
                    
                } catch (Exception $e) {
                    $this->log("Error parsing active inmate row: " . $e->getMessage(), 'warning');
                }
                
            } elseif ($isReleasedInmates && $cells->length >= 7) {
                // Released Inmates: Docket, Intake, Released, Name, Age, Charge, Bond
                try {
                    $docketCell = $cells->item(0);
                    $docketLink = $xpath->query('.//a', $docketCell)->item(0);
                    $docketNumber = $docketLink ? trim($docketLink->textContent) : trim($docketCell->textContent);
                    
                    $detailUrl = null;
                    if ($docketLink) {
                        $href = $docketLink->getAttribute('href');
                        if (!empty($href)) {
                            $detailUrl = (strpos($href, 'http') === 0) ? $href : $this->baseUrl . '/' . ltrim($href, '/');
                        }
                    }
                    
                    $intakeRaw = trim($cells->item(1)->textContent);
                    $intakeRaw = preg_replace('/\s+/', ' ', $intakeRaw);
                    
                    $releasedRaw = trim($cells->item(2)->textContent);
                    $releasedRaw = preg_replace('/\s+/', ' ', $releasedRaw);
                    $isInJail = stripos($releasedRaw, 'IN JAIL') !== false;
                    
                    $name = trim($cells->item(3)->textContent);
                    $age = trim($cells->item(4)->textContent);
                    $charge = trim($cells->item(5)->textContent);
                    $bondRaw = trim($cells->item(6)->textContent);
                    $bondRaw = preg_replace('/\s+/', ' ', $bondRaw);
                    
                    // Parse bond info
                    $bondStatus = '';
                    if (stripos($bondRaw, 'NOT READY') !== false) {
                        $bondStatus = 'NOT READY';
                    } elseif (stripos($bondRaw, 'READY') !== false) {
                        $bondStatus = 'READY';
                    }
                    
                    $bondAmount = '';
                    $bondType = '';
                    if (preg_match('/Cash:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $bondType = 'Cash';
                        $bondAmount = '$' . $matches[1];
                    } elseif (preg_match('/Property:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $bondType = 'Property';
                        $bondAmount = '$' . $matches[1];
                    } elseif (stripos($bondRaw, 'No Amount Set') !== false) {
                        $bondAmount = 'No Amount Set';
                    }
                    
                    $fees = '';
                    if (preg_match('/Fees:\s*\$?\s*([0-9,.]+)/i', $bondRaw, $matches)) {
                        $fees = '$' . $matches[1];
                    }
                    
                    $fullBondInfo = $bondStatus;
                    if ($bondAmount) {
                        $fullBondInfo .= ($fullBondInfo ? ' | ' : '') . ($bondType ? $bondType . ': ' : '') . $bondAmount;
                    }
                    if ($fees) {
                        $fullBondInfo .= ' + Fees: ' . $fees;
                    }
                    
                    if (empty($name) || strlen($name) < 3) continue;
                    if (empty($docketNumber) || strlen($docketNumber) < 3) continue;
                    
                    $inmate = [
                        'docket_number' => $docketNumber,
                        'name' => $name,
                        'le_number' => null,
                        'age' => $age,
                        'booking_date' => $intakeRaw,
                        'released_date' => $isInJail ? 'IN JAIL' : $releasedRaw,
                        'charge' => $charge,
                        'bond_info' => $fullBondInfo,
                        'in_jail' => $isInJail,
                        'detail_url' => $detailUrl
                    ];
                    
                    $inmates[] = $inmate;
                    $this->log("Parsed (RELEASED): {$name} (Docket: {$docketNumber})");
                    
                } catch (Exception $e) {
                    $this->log("Error parsing released inmate row: " . $e->getMessage(), 'warning');
                }
            }
        }
        
        $this->log("Successfully parsed " . count($inmates) . " inmates");
        return $inmates;
    }
    
    private function determineChargeType($charge) {
        $charge = strtoupper($charge);
        
        $felonyKeywords = [
            'MURDER', 'RAPE', 'AGGRAVATED', 'ARMED ROBBERY', 'KIDNAPPING',
            'TRAFFICKING', 'BURGLARY', 'FELONY', 'GUN', 'WEAPON',
            'SEXUAL BATTERY', 'CHILD MOLESTATION', 'ARSON', 'HOMICIDE'
        ];
        
        foreach ($felonyKeywords as $keyword) {
            if (stripos($charge, $keyword) !== false) {
                return 'Felony';
            }
        }
        
        $misdemeanorKeywords = [
            'MISDEMEANOR', 'DUI', 'SHOPLIFTING', 'SIMPLE', 'TRESPASSING',
            'DISORDERLY', 'PUBLIC DRUNK', 'MARIJUANA'
        ];
        
        foreach ($misdemeanorKeywords as $keyword) {
            if (stripos($charge, $keyword) !== false) {
                return 'Misdemeanor';
            }
        }
        
        return 'Unknown';
    }
    
    private function saveInmates($inmates) {
        $saved = 0;
        $updated = 0;
        
        foreach ($inmates as $inmate) {
            try {
                // Validate required fields
                if (empty($inmate['docket_number']) || empty($inmate['name'])) {
                    $this->log("Skipping inmate with missing docket or name", 'warning');
                    continue;
                }
                
                // Check if inmate exists
                $stmt = $this->db->prepare("SELECT id FROM inmates WHERE docket_number = ?");
                $stmt->execute([$inmate['docket_number']]);
                $existingId = $stmt->fetchColumn();
                
                // Parse name into first/last
                $nameParts = explode(' ', $inmate['name'], 2);
                $lastName = $nameParts[0] ?? '';
                $firstName = $nameParts[1] ?? '';
                
                if ($existingId) {
                    // Update existing
                    $stmt = $this->db->prepare("
                        UPDATE inmates SET
                            name = ?,
                            first_name = ?,
                            last_name = ?,
                            age = ?,
                            le_number = ?,
                            booking_date = COALESCE(?, booking_date),
                            release_date = ?,
                            bond_amount = ?,
                            in_jail = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $inmate['name'],
                        $firstName,
                        $lastName,
                        $inmate['age'],
                        $inmate['le_number'] ?? null,
                        $inmate['booking_date'] ?? null,
                        $inmate['released_date'] ?? 'UNKNOWN',
                        $inmate['bond_info'],
                        $inmate['in_jail'] ? 1 : 0,
                        $existingId
                    ]);
                    $updated++;
                } else {
                    // Insert new
                    $stmt = $this->db->prepare("
                        INSERT INTO inmates (
                            docket_number, inmate_id, name, first_name, last_name,
                            age, le_number, booking_date, release_date, bond_amount, in_jail
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $inmate['docket_number'],
                        $inmate['docket_number'],
                        $inmate['name'],
                        $firstName,
                        $lastName,
                        $inmate['age'],
                        $inmate['le_number'] ?? null,
                        $inmate['booking_date'] ?? null,
                        $inmate['released_date'] ?? 'UNKNOWN',
                        $inmate['bond_info'],
                        $inmate['in_jail'] ? 1 : 0
                    ]);
                    $saved++;
                }
                
                // Save detail URL
                if (!empty($inmate['detail_url'])) {
                    $stmt = $this->db->prepare("
                        INSERT OR REPLACE INTO inmate_detail_urls
                        (inmate_id, detail_url, scraped, updated_at)
                        VALUES (?, ?, 0, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([
                        $inmate['docket_number'],
                        $inmate['detail_url']
                    ]);
                }
                
                // Save charge
                if (!empty($inmate['charge'])) {
                    $chargeType = $this->determineChargeType($inmate['charge']);
                    
                    $stmt = $this->db->prepare("
                        INSERT INTO charges (inmate_id, charge_description, charge_type)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $inmate['docket_number'],
                        $inmate['charge'],
                        $chargeType
                    ]);
                }
                
            } catch (PDOException $e) {
                $this->log("Error saving inmate {$inmate['name']}: " . $e->getMessage(), 'error');
            }
        }
        
        $this->log("Saved: $saved new, Updated: $updated existing inmates");
        return $saved + $updated;
    }
    
    private function logScrape($status, $count = 0, $message = '', $error = '') {
        $stmt = $this->db->prepare("
            INSERT INTO scrape_logs (status, inmates_found, message, error_details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$status, $count, $message, $error]);
    }
    
    public function scrapeAllPages($startUrl) {
        try {
            $url = $startUrl;
            $pageNumber = 1;
            $allInmates = [];
            $maxPages = 1000;
            $visitedUrls = [];
            
            while ($url && $pageNumber <= $maxPages) {
                if (in_array($url, $visitedUrls)) {
                    $this->log("Already visited URL - stopping");
                    break;
                }
                
                $visitedUrls[] = $url;
                $this->log("=== PAGE $pageNumber ===");
                
                try {
                    $html = $this->fetchPage($url);
                    $inmates = $this->parseInmates($html);
                    
                    if (count($inmates) > 0) {
                        $allInmates = array_merge($allInmates, $inmates);
                        $this->log("Found " . count($inmates) . " inmates on page $pageNumber (Total: " . count($allInmates) . ")");
                    } else {
                        $this->log("No inmates found - stopping", 'warning');
                        break;
                    }
                    
                    $nextUrl = $this->findNextPageLink($html);
                    
                    if ($nextUrl && $nextUrl !== $url) {
                        $this->log("Found NEXT link: $nextUrl");
                        $url = $nextUrl;
                        $pageNumber++;
                        sleep(2);
                    } else {
                        $this->log("No more pages");
                        break;
                    }
                    
                } catch (Exception $e) {
                    $this->log("Error on page $pageNumber: " . $e->getMessage(), 'error');
                    break;
                }
            }
            
            if ($pageNumber > $maxPages) {
                $this->log("Reached max pages ($maxPages)", 'warning');
            }
            
            return $allInmates;
            
        } catch (Exception $e) {
            $this->log("Scraping error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    public function scrapeAll() {
        try {
            $this->log("========================================");
            $this->log("Starting COMPLETE scrape");
            $this->log("========================================");
            
            $allInmates = [];
            $scrapeUrls = SCRAPE_URLS;
            
            foreach ($scrapeUrls as $period => $url) {
                $this->log("\n>>> Scraping: $period <<<");
                $this->log("URL: $url\n");
                
                $inmates = $this->scrapeAllPages($url);
                
                if (!empty($inmates)) {
                    $allInmates = array_merge($allInmates, $inmates);
                    $this->log("Completed $period: " . count($inmates) . " inmates");
                } else {
                    $this->log("No inmates found for $period", 'warning');
                }
                
                sleep(3);
            }
            
            if (!empty($allInmates)) {
                // Deduplicate
                $uniqueInmates = [];
                $seenDockets = [];
                
                foreach ($allInmates as $inmate) {
                    if (!in_array($inmate['docket_number'], $seenDockets)) {
                        $uniqueInmates[] = $inmate;
                        $seenDockets[] = $inmate['docket_number'];
                    }
                }
                
                $this->log("\n========================================");
                $this->log("Total unique inmates: " . count($uniqueInmates));
                $this->log("========================================\n");
                
                $saved = $this->saveInmates($uniqueInmates);
                
                $this->log("Complete scrape finished: $saved inmates processed");
                $this->logScrape('success', $saved, "Successfully scraped $saved inmates");
                
                return $saved;
            } else {
                $this->log("No inmates found");
                $this->logScrape('success', 0, "No inmates found");
                return 0;
            }
            
        } catch (Exception $e) {
            $this->log("Scrape failed: " . $e->getMessage(), 'error');
            $this->logScrape('error', 0, 'Scrape failed', $e->getMessage());
            throw $e;
        }
    }
    
    public function scrape() {
        return $this->scrapeAll();
    }
    
    public function run48Hours() {
        $startTime = time();
        $endTime = $startTime + SCRAPE_DURATION;
        
        while (time() < $endTime) {
            $this->scrapeAll();
            
            $remainingTime = $endTime - time();
            $sleepTime = min(SCRAPE_INTERVAL, $remainingTime);
            $this->log("Sleeping for " . ($sleepTime / 60) . " minutes...");
            sleep($sleepTime);
        }
        
        $this->log("48-hour run complete");
    }
}

// Run
if (php_sapi_name() === 'cli') {
    $scraper = new JailScraper();
    
    if (isset($argv[1]) && $argv[1] === '--once') {
        $scraper->scrape();
    } else {
        $scraper->run48Hours();
    }
} else {
    echo "Usage: php scraper.php [--once]\n";
}
?>