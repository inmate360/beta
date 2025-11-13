<?php
/**
 * AJAX Endpoint for Fetching Inmate Details
 * This handles the modal detail requests from index.php
 */

session_start();
require_once 'config.php';
require_once 'invite_gate.php';

// Check access
checkInviteAccess();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$dkt = trim($_POST['dkt'] ?? '');
$le  = trim($_POST['le'] ?? '');

if (empty($dkt)) {
    echo json_encode(['success' => false, 'message' => 'Missing docket (dkt) parameter']);
    exit;
}

try {
    // Initialize database
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Build remote URL
    $remote = 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj205r.pgm?dkt=' . urlencode($dkt);
    if (!empty($le)) {
        $remote .= '&le=' . urlencode($le);
    }
    
    // Fetch remote page
    $ch = curl_init($remote);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => defined('TIMEOUT') ? TIMEOUT : 15,
        CURLOPT_USERAGENT => defined('USER_AGENT') ? USER_AGENT : 'Inmate360/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    if ($curlErr) {
        echo json_encode(['success' => false, 'message' => 'Connection error: ' . $curlErr]);
        exit;
    }
    
    if ($httpCode !== 200 || !$html) {
        echo json_encode(['success' => false, 'message' => "Remote server returned HTTP $httpCode"]);
        exit;
    }
    
    // Parse HTML for key/value pairs
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    
    $details = [
        'inmate_id'    => $dkt,
        'le_number'    => $le,
        'name'         => null,
        'age'          => null,
        'sex'          => null,
        'race'         => null,
        'height'       => null,
        'weight'       => null,
        'booking_date' => null,
        'release_date' => null,
        'bond_amount'  => null,
        'charges'      => []
    ];
    
    // Extract label/value pairs from table rows
    $rows = $xpath->query('//tr');
    foreach ($rows as $row) {
        $cells = $xpath->query('.//td|.//th', $row);
        if ($cells->length === 2) {
            $label = trim($cells->item(0)->textContent);
            $value = trim($cells->item(1)->textContent);
            $labelLow = strtolower($label);
            
            if (strpos($labelLow, 'name') !== false && empty($details['name'])) {
                $details['name'] = $value;
            } elseif (preg_match('/\bage\b/i', $label) && empty($details['age'])) {
                $details['age'] = $value;
            } elseif (preg_match('/sex|gender/i', $label) && empty($details['sex'])) {
                $details['sex'] = $value;
            } elseif (preg_match('/race/i', $label) && empty($details['race'])) {
                $details['race'] = $value;
            } elseif (preg_match('/height/i', $label) && empty($details['height'])) {
                $details['height'] = $value;
            } elseif (preg_match('/weight/i', $label) && empty($details['weight'])) {
                $details['weight'] = $value;
            } elseif (preg_match('/booking.*date|booked/i', $label) && empty($details['booking_date'])) {
                $details['booking_date'] = $value;
            } elseif (preg_match('/release.*date|released/i', $label) && empty($details['release_date'])) {
                $details['release_date'] = $value;
            } elseif (preg_match('/bond/i', $label) && empty($details['bond_amount'])) {
                $details['bond_amount'] = $value;
            } elseif (preg_match('/charge|offense/i', $label)) {
                $candidates = array_filter(array_map('trim', preg_split('/[;,\n\r]+/', $value)));
                foreach ($candidates as $c) {
                    if (!empty($c)) $details['charges'][] = $c;
                }
            }
        }
    }
    
    // Fallback: Parse charges from body text
    $bodyNode = $xpath->query('//body')->item(0);
    $bodyText = $bodyNode ? preg_replace('/\s+/', ' ', $bodyNode->textContent) : '';
    
    if (empty($details['charges'])) {
        if (preg_match_all('/(?:Charge|Offense)[\s:\-]+(.+?)(?=Charge|Offense|Disposition|Bond|$)/is', $bodyText, $m)) {
            foreach ($m[1] as $c) {
                $c = trim($c);
                if (!empty($c) && strlen($c) < 200) {
                    $details['charges'][] = $c;
                }
            }
        }
    }
    
    // Save to database
    $db->beginTransaction();
    
    try {
        // Check if inmate exists
        $sel = $db->prepare("SELECT id FROM inmates WHERE inmate_id = ? OR docket_number = ? LIMIT 1");
        $sel->execute([$dkt, $dkt]);
        $existingId = $sel->fetchColumn();
        
        if ($existingId) {
            // Update existing inmate
            $upd = $db->prepare("
                UPDATE inmates SET
                    name = COALESCE(?, name),
                    age = COALESCE(?, age),
                    sex = COALESCE(?, sex),
                    race = COALESCE(?, race),
                    height = COALESCE(?, height),
                    weight = COALESCE(?, weight),
                    booking_date = COALESCE(?, booking_date),
                    release_date = COALESCE(?, release_date),
                    bond_amount = COALESCE(?, bond_amount),
                    le_number = COALESCE(?, le_number),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([
                $details['name'] ?? null,
                $details['age'] ?? null,
                $details['sex'] ?? null,
                $details['race'] ?? null,
                $details['height'] ?? null,
                $details['weight'] ?? null,
                $details['booking_date'] ?? null,
                $details['release_date'] ?? null,
                $details['bond_amount'] ?? null,
                $details['le_number'] ?? null,
                $existingId
            ]);
        } else {
            // Insert new inmate
            $ins = $db->prepare("
                INSERT INTO inmates (
                    docket_number, inmate_id, name, first_name, last_name,
                    age, sex, race, height, weight, booking_date, release_date,
                    bond_amount, le_number, in_jail, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            
            // Parse name
            $firstName = null;
            $lastName = null;
            if (!empty($details['name'])) {
                $parts = preg_split('/\s+/', $details['name'], 2);
                $firstName = $parts[0] ?? null;
                $lastName = $parts[1] ?? null;
            }
            
            $ins->execute([
                $dkt,
                $dkt,
                $details['name'] ?? null,
                $firstName,
                $lastName,
                $details['age'] ?? null,
                $details['sex'] ?? null,
                $details['race'] ?? null,
                $details['height'] ?? null,
                $details['weight'] ?? null,
                $details['booking_date'] ?? null,
                $details['release_date'] ?? null,
                $details['bond_amount'] ?? null,
                $details['le_number'] ?? null,
                1
            ]);
        }
        
        // Save charges
        if (!empty($details['charges'])) {
            $existingChargesStmt = $db->prepare("SELECT charge_description FROM charges WHERE inmate_id = ?");
            $existingChargesStmt->execute([$dkt]);
            $existingCharges = $existingChargesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $insertCharge = $db->prepare("INSERT INTO charges (inmate_id, charge_description, charge_type, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            
            foreach ($details['charges'] as $chargeText) {
                // Simple duplicate check
                $found = false;
                foreach ($existingCharges as $ec) {
                    if (stripos($ec, $chargeText) !== false || stripos($chargeText, $ec) !== false) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    // Determine charge type
                    $chargeType = null;
                    $uc = strtoupper($chargeText);
                    if (preg_match('/FELONY|AGGRAVATED|MURDER|ROBBERY|RAPE|KIDNAPPING|TRAFFICKING|ARMED/', $uc)) {
                        $chargeType = 'Felony';
                    } elseif (preg_match('/MISDEMEANOR|DUI|BATTERY|THEFT|TRESPASS|DISORDERLY/', $uc)) {
                        $chargeType = 'Misdemeanor';
                    }
                    
                    $insertCharge->execute([$dkt, $chargeText, $chargeType]);
                }
            }
        }
        
        // Save detail URL
        try {
            $iri = $db->prepare("
                INSERT OR REPLACE INTO inmate_detail_urls (inmate_id, detail_url, scraped, scrape_attempts, last_scrape_attempt)
                VALUES (?, ?, 1, 1, CURRENT_TIMESTAMP)
            ");
            $iri->execute([$dkt, $remote]);
        } catch (Exception $e) {
            // Table might not exist, ignore
        }
        
        $db->commit();
        
        // Return parsed details
        echo json_encode(['success' => true, 'details' => $details, 'message' => 'Details fetched successfully']);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>