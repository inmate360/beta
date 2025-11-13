<?php
/**
 * Inmate360 - Live Scraper for Active Inmates (v12 - Fixed Parsing & Validation)
 * Properly validates data before saving to prevent placeholder/dummy values
 */

if (php_sapi_name() === 'cli' && !defined('DB_PATH')) {
    require_once __DIR__ . '/config.php';
}

function _ls_fetch_page($url) {
    $log_prefix = '[live_scraper]';
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_FOLLOWLOCATION => true, 
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Inmate360/1.0', 
            CURLOPT_SSL_VERIFYPEER => false, 
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("$log_prefix HTTP $httpCode for $url");
            return null;
        }
        return $html;
    } catch (Exception $e) {
        error_log("$log_prefix Exception fetching $url: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate that a name is real and not a placeholder
 */
function _ls_is_valid_name($name) {
    if (empty($name)) return false;
    
    // Reject placeholder/dummy values
    $rejects = [
        'Name Type',
        '*IN JAIL*',
        'IN JAIL',
        'INMATE',
        'INFORMATION',
        'UNKNOWN',
        'N/A',
        'NULL',
        'TEST',
        'PLACEHOLDER',
        'DUMMY',
        'ADMIN',
        'SYSTEM',
        'TEMPORARY'
    ];
    
    $upper = strtoupper(trim($name));
    foreach ($rejects as $reject) {
        if ($upper === strtoupper($reject) || strpos($upper, strtoupper($reject)) !== false) {
            return false;
        }
    }
    
    // Must have at least 2 characters and contain letters
    if (strlen($name) < 2 || !preg_match('/[a-zA-Z]/', $name)) {
        return false;
    }
    
    return true;
}

/**
 * Validate date format
 */
function _ls_is_valid_date($date) {
    if (empty($date)) return false;
    $date = trim($date);
    // Try to parse as date
    $timestamp = strtotime($date);
    if ($timestamp === false) return false;
    // Date should be recent (not in future, not before 1980)
    $year = date('Y', $timestamp);
    return $year >= 1980 && $year <= date('Y');
}

/**
 * Validate booking date is reasonable
 */
function _ls_normalize_date($date) {
    if (empty($date)) return null;
    $date = trim($date);
    if (!_ls_is_valid_date($date)) return null;
    $timestamp = strtotime($date);
    return date('Y-m-d H:i:s', $timestamp);
}

function _ls_parse_inmate_details($detail_html) {
    if (!$detail_html) return [];
    
    $details = [];
    $text = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $detail_html));
    $text = preg_replace('/\s+/', ' ', $text);
    
    $patterns = [
        'sex' => '/Sex:\s*([A-Z])/i',
        'race' => '/Race:\s*([A-Za-z\s]+?)(?:\s|$|,)/i',
        'height' => '/Height:\s*([0-9\'\s"]+)/i',
        'weight' => '/Weight:\s*(\d+(?:\s*lbs)?)/i',
        'booking_date' => '/Booking Date:\s*([0-9\/\-\s:APMapm]+)/i',
    ];
    
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $value = trim($matches[1]);
            if (!empty($value)) {
                if ($key === 'booking_date') {
                    $details[$key] = _ls_normalize_date($value);
                } else {
                    $details[$key] = $value;
                }
            }
        }
    }
    return $details;
}

function _ls_build_valid_url($href, $base_url) {
    if (!$href) return null;
    $parts = parse_url($href);
    $path = $parts['path'] ?? '';
    $final_url = rtrim($base_url, '/') . $path;
    if (isset($parts['query'])) {
        parse_str(html_entity_decode($parts['query']), $query_params);
        $final_url .= '?' . http_build_query($query_params);
    }
    return $final_url;
}

function _ls_charge_exists($charges, $docket_number, $description) {
    foreach ($charges as $existing) {
        if ($existing['docket_number'] === $docket_number || 
            stripos($existing['description'], trim($description)) !== false ||
            stripos(trim($description), $existing['description']) !== false) {
            return true;
        }
    }
    return false;
}

function _ls_save_batch($db, $inmate_batch) {
    if (empty($inmate_batch)) return 0;
    
    $saved_count = 0;
    error_log("[live_scraper] Saving batch of " . count($inmate_batch) . " inmates");
    
    foreach ($inmate_batch as $inmate) {
        try {
            // VALIDATION: Skip inmates with invalid names
            if (!_ls_is_valid_name($inmate['name'])) {
                error_log("[live_scraper] Skipping inmate with invalid name: " . json_encode($inmate));
                continue;
            }
            
            $db->beginTransaction();
            
            // Use le_number as primary key if available, otherwise use inmate_id/docket
            $primary_key = !empty($inmate['le_number']) ? $inmate['le_number'] : $inmate['inmate_id'];
            
            if (empty($primary_key)) {
                error_log("[live_scraper] Skipping inmate with no identifier");
                $db->rollBack();
                continue;
            }
            
            // Check if inmate already exists
            $checkStmt = $db->prepare("SELECT id FROM inmates WHERE inmate_id = ?");
            $checkStmt->execute([$primary_key]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists) {
                // Update existing inmate - only update fields that have valid values
                $updateStmt = $db->prepare("
                    UPDATE inmates SET 
                        name = COALESCE(NULLIF(?, ''), name),
                        age = COALESCE(NULLIF(?, ''), age),
                        sex = COALESCE(NULLIF(?, ''), sex),
                        race = COALESCE(NULLIF(?, ''), race),
                        height = COALESCE(NULLIF(?, ''), height),
                        weight = COALESCE(NULLIF(?, ''), weight),
                        le_number = COALESCE(NULLIF(?, ''), le_number),
                        booking_date = COALESCE(NULLIF(?, ''), booking_date),
                        bond_amount = COALESCE(NULLIF(?, ''), bond_amount),
                        in_jail = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE inmate_id = ?
                ");
                $updateStmt->execute([
                    _ls_is_valid_name($inmate['name']) ? $inmate['name'] : '',
                    (!empty($inmate['age']) && is_numeric($inmate['age'])) ? $inmate['age'] : '',
                    $inmate['sex'] ?: '',
                    $inmate['race'] ?: '',
                    $inmate['height'] ?: '',
                    $inmate['weight'] ?: '',
                    $inmate['le_number'] ?: '',
                    $inmate['booking_date'] ?: '',
                    $inmate['bond_amount'] ?: '',
                    $primary_key
                ]);
                error_log("[live_scraper] Updated existing inmate: $primary_key ({$inmate['name']})");
            } else {
                // Insert new inmate
                $insertStmt = $db->prepare("
                    INSERT INTO inmates (
                        inmate_id, docket_number, name, age, sex, race, height, weight, 
                        le_number, booking_date, bond_amount, in_jail, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                
                // Only insert valid values
                $age = (!empty($inmate['age']) && is_numeric($inmate['age'])) ? $inmate['age'] : null;
                $booking_date = _ls_is_valid_date($inmate['booking_date'] ?? null) ? $inmate['booking_date'] : null;
                
                $insertStmt->execute([
                    $primary_key,
                    $inmate['inmate_id'] ?? null,
                    $inmate['name'] ?? null,
                    $age,
                    $inmate['sex'] ?: null,
                    $inmate['race'] ?: null,
                    $inmate['height'] ?: null,
                    $inmate['weight'] ?: null,
                    $inmate['le_number'] ?: null,
                    $booking_date,
                    $inmate['bond_amount'] ?: null
                ]);
                error_log("[live_scraper] Inserted new inmate: $primary_key ({$inmate['name']})");
            }
            
            // Delete old charges for this inmate to avoid duplicates
            $delStmt = $db->prepare("DELETE FROM charges WHERE inmate_id = ?");
            $delStmt->execute([$primary_key]);
            
            // Insert all charges for this inmate
            $chargeStmt = $db->prepare("
                INSERT INTO charges (inmate_id, charge_description, docket_number, created_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ");
            
            $charge_count = 0;
            foreach ($inmate['charges'] as $charge) {
                $chargeStmt->execute([
                    $primary_key, 
                    $charge['description'] ?? '', 
                    $charge['docket_number'] ?? ''
                ]);
                $charge_count++;
            }
            
            error_log("[live_scraper] Added $charge_count charges for inmate $primary_key");
            
            $db->commit();
            $saved_count++;
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("[live_scraper] Error saving inmate: " . $e->getMessage());
        }
    }
    
    return $saved_count;
}

function fetch_and_store_live_inmates() {
    $sources = [
        'Active Inmates' => 'http://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj201r.pgm',
        '48-Hour Docket' => 'http://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj210r.pgm?days=02&rtype=F'
    ];
    $base_url = 'http://weba.claytoncountyga.gov';
    
    error_log("[live_scraper] ========== STARTING LIVE INMATE SCRAPE ==========");
    error_log("[live_scraper] PHASE 1: Discovery from all sources.");
    
    $inmates_to_process = [];
    $discovery_count = 0;

    foreach ($sources as $source_name => $start_url) {
        error_log("[live_scraper] Scraping source: '{$source_name}' from $start_url");
        $current_url = $start_url;
        $page_count = 0;
        $max_pages = 50;
        $source_count = 0;

        while ($current_url && $page_count < $max_pages) {
            $page_count++;
            error_log("[live_scraper] {$source_name} - Page $page_count");
            
            $list_html = _ls_fetch_page($current_url);
            if (!$list_html) {
                error_log("[live_scraper] Failed to fetch page from {$source_name}");
                break;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($list_html);
            $xpath = new DOMXPath($dom);
            $rows = $xpath->query('//table/tr[position()>1]'); // Skip header row

            if ($rows->length === 0) {
                error_log("[live_scraper] No rows found in {$source_name} page $page_count");
                break;
            }

            foreach ($rows as $row) {
                $cells = $xpath->query('.//td', $row);
                if ($cells->length < 6) continue;

                // Extract data from cells
                $docket_link = $xpath->query('.//a', $cells->item(0))->item(0);
                if (!$docket_link) continue;
                
                $docket_number = trim($docket_link->textContent);
                $detail_href = $docket_link->getAttribute('href');
                $name = trim($cells->item(1)->textContent);
                $le_number = trim($cells->item(2)->textContent);
                $age = trim($cells->item(3)->textContent);
                $charge_description = trim($cells->item(4)->textContent);
                $bond_amount = trim($cells->item(5)->textContent);
                
                // VALIDATION: Skip if name is invalid
                if (!_ls_is_valid_name($name)) {
                    error_log("[live_scraper] Skipping invalid name: '$name'");
                    continue;
                }
                
                if (empty($le_number) && empty($docket_number)) continue;
                
                // Use le_number as primary identifier if available, otherwise use docket
                $inmate_id = !empty($le_number) ? $le_number : $docket_number;
                
                // Initialize inmate if not seen before
                if (!isset($inmates_to_process[$inmate_id])) {
                    $inmates_to_process[$inmate_id] = [
                        'inmate_id' => $inmate_id,
                        'le_number' => $le_number ?: null,
                        'name' => $name ?: null,
                        'age' => $age ?: null,
                        'detail_url' => _ls_build_valid_url($detail_href, $base_url),
                        'bond_amount' => $bond_amount ?: null,
                        'charges' => [],
                        'sex' => null,
                        'race' => null,
                        'height' => null,
                        'weight' => null,
                        'booking_date' => null
                    ];
                    error_log("[live_scraper] Discovered new inmate: $inmate_id - $name");
                    $discovery_count++;
                }
                
                // Add charge if not already present for this inmate
                if (!_ls_charge_exists($inmates_to_process[$inmate_id]['charges'], $docket_number, $charge_description)) {
                    $inmates_to_process[$inmate_id]['charges'][] = [
                        'docket_number' => $docket_number ?: null,
                        'description' => $charge_description ?: 'Unknown Charge'
                    ];
                    error_log("[live_scraper] Added charge to $inmate_id: $charge_description");
                }
                
                $source_count++;
            }
            
            error_log("[live_scraper] {$source_name} page $page_count: Found $source_count rows so far");
            
            // Find next page link
            $next_link = $xpath->query('//a[contains(text(), "NEXT")]')->item(0);
            $current_url = $next_link ? _ls_build_valid_url($next_link->getAttribute('href'), $base_url) : null;
            
            // Be polite to the server
            usleep(500000);
        }
        
        error_log("[live_scraper] Completed {$source_name}: {$source_count} total entries, {$discovery_count} unique inmates");
    }
    
    error_log("[live_scraper] PHASE 1 complete. Found " . count($inmates_to_process) . " unique inmates.");

    // PHASE 2: Fetch details and save
    error_log("[live_scraper] PHASE 2: Fetching details and saving to database.");
    
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        error_log("[live_scraper] FATAL: Database connection failed: " . $e->getMessage());
        return 0;
    }
    
    $batch = [];
    $batch_size = 10;
    $total_saved = 0;
    $total_inmates = count($inmates_to_process);
    $current = 0;
    
    foreach ($inmates_to_process as $inmate_id => $inmate) {
        $current++;
        
        // Fetch detail page if available
        if ($inmate['detail_url']) {
            $detail_html = _ls_fetch_page($inmate['detail_url']);
            if ($detail_html) {
                $extra_details = _ls_parse_inmate_details($detail_html);
                // Only merge if we got new data
                foreach ($extra_details as $key => $value) {
                    if (!empty($value) && empty($inmate[$key])) {
                        $inmate[$key] = $value;
                    }
                }
                error_log("[live_scraper] Fetched detail page for $inmate_id");
            }
            usleep(300000); // Be polite to the server
        }
        
        $batch[] = $inmate;
        
        // Save batch when it reaches the size limit or we're at the end
        if (count($batch) >= $batch_size || $current == $total_inmates) {
            $saved = _ls_save_batch($db, $batch);
            $total_saved += $saved;
            error_log("[live_scraper] Progress: $current/$total_inmates inmates. Batch saved: $saved");
            $batch = [];
        }
    }
    
    error_log("[live_scraper] ========== SCRAPE COMPLETE ==========");
    error_log("[live_scraper] Total inmates saved/updated: $total_saved out of " . count($inmates_to_process));
    
    return $total_saved;
}

// CLI execution
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/config.php';
    echo "Starting live inmate scraper...\n";
    echo "Scraping both Active Inmates and 48-Hour Docket...\n";
    echo "================================================\n\n";
    
    try {
        $count = fetch_and_store_live_inmates();
        echo "\n================================================\n";
        echo "Finished. Processed $count valid inmates.\n";
        exit(0);
    } catch (Exception $e) {
        echo "\n================================================\n";
        echo "Error: " . $e->getMessage() . "\n";
        error_log("[live_scraper_cli] FATAL: " . $e->getMessage());
        exit(1);
    }
}
?>