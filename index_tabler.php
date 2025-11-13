<?php
/**
 * Inmate360 Professional Dashboard
 * Community Safety & Law Enforcement Analytics Platform
 * Clayton County, Georgia
 */

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'invite_gate.php';

checkInviteAccess();

// Initialize database
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * AJAX endpoint: fetch_inmate_details
 * Called when the user clicks "View" - will fetch remote county detail page,
 * parse, save to DB (inmates, charges, inmate_detail_urls, inmate_case_details),
 * and return JSON with parsed fields for the modal.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_inmate_details') {
    header('Content-Type: application/json; charset=utf-8');

    // Basic access check - invite gate ran earlier
    $dkt = trim($_POST['dkt'] ?? '');
    $le  = trim($_POST['le'] ?? '');

    if (empty($dkt)) {
        echo json_encode(['success' => false, 'message' => 'Missing docket (dkt) parameter']);
        exit;
    }

    // Build remote URL
    $remote = 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj205r.pgm?dkt=' . urlencode($dkt);
    if (!empty($le)) {
        $remote .= '&le=' . urlencode($le);
    }

    try {
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
            echo json_encode(['success' => false, 'message' => 'cURL error: ' . $curlErr]);
            exit;
        }
        if ($httpCode !== 200 || !$html) {
            echo json_encode(['success' => false, 'message' => "Remote returned HTTP $httpCode or empty content"]);
            exit;
        }

        // Parse HTML for key/value pairs and free-text fallbacks
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

        // Fallback: title may include name
        if (empty($details['name'])) {
            $title = $xpath->query('//title')->item(0);
            if ($title) {
                $t = trim($title->textContent);
                if (!empty($t) && strlen($t) < 200) {
                    $details['name'] = $t;
                }
            }
        }

        // Fallback: search body text for charges
        $bodyNode = $xpath->query('//body')->item(0);
        $bodyText = $bodyNode ? preg_replace('/\s+/', ' ', $bodyNode->textContent) : '';
        if (empty($details['charges'])) {
            if (preg_match_all('/(?:Charge|Offense)[\s:\-]+(.+?)(?=Charge|Offense|Disposition|Bond|$)/is', $bodyText, $m)) {
                foreach ($m[1] as $c) {
                    $c = trim($c);
                    if (!empty($c)) $details['charges'][] = $c;
                }
            } else {
                if (preg_match_all('/(?:CHARGE|OFFENSE)\s*[:\-]\s*([^;.\n]+)/i', $bodyText, $m2)) {
                    foreach ($m2[1] as $c) {
                        $c = trim($c);
                        if (!empty($c)) $details['charges'][] = $c;
                    }
                }
            }
        }

        // Normalize bond if missing
        if (empty($details['bond_amount'])) {
            if (preg_match('/\$([0-9\.,]+)/', $bodyText, $m)) {
                $details['bond_amount'] = '$' . str_replace(',', '', $m[1]);
            }
        }

        // Save parsed data into DB
        try {
            $db->beginTransaction();

            // Find existing inmate
            $sel = $db->prepare("SELECT id FROM inmates WHERE inmate_id = ? OR docket_number = ? LIMIT 1");
            $sel->execute([$dkt, $dkt]);
            $existingId = $sel->fetchColumn();

            if ($existingId) {
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
                $inmateDbId = $existingId;
            } else {
                $ins = $db->prepare("
                    INSERT INTO inmates (
                        docket_number, inmate_id, name, first_name, last_name,
                        age, sex, race, height, weight, booking_date, release_date,
                        bond_amount, le_number, in_jail, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
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
                $inmateDbId = $db->lastInsertId();
            }

            // Insert/update inmate_detail_urls (attempt safe upsert)
            try {
                $detailUrl = $remote;
                $iri = $db->prepare("
                    INSERT INTO inmate_detail_urls (inmate_id, detail_url, scraped, scrape_attempts, last_scrape_attempt, created_at, updated_at)
                    VALUES (?, ?, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT(inmate_id) DO UPDATE SET
                        detail_url = excluded.detail_url,
                        scraped = 1,
                        scrape_attempts = inmate_detail_urls.scrape_attempts + 1,
                        last_scrape_attempt = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                ");
                $iri->execute([$dkt, $detailUrl]);
            } catch (Exception $e) {
                // fallback: create table if missing & insert or replace
                try {
                    $db->exec("CREATE TABLE IF NOT EXISTS inmate_detail_urls (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        inmate_id TEXT UNIQUE NOT NULL,
                        detail_url TEXT NOT NULL,
                        scraped INTEGER DEFAULT 0,
                        scrape_attempts INTEGER DEFAULT 0,
                        last_scrape_attempt DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                    $iri2 = $db->prepare("INSERT OR REPLACE INTO inmate_detail_urls (inmate_id, detail_url, scraped, scrape_attempts, last_scrape_attempt, created_at, updated_at) VALUES (?, ?, 1, 1, C[...]
                    $iri2->execute([$dkt, $detailUrl]);
                } catch (Exception $e2) {
                    // ignore
                }
            }

            // Save charges - avoid duplicates simple heuristic
            if (!empty($details['charges'])) {
                $existingChargesStmt = $db->prepare("SELECT charge_description FROM charges WHERE inmate_id = ?");
                $existingChargesStmt->execute([$dkt]);
                $existingCharges = $existingChargesStmt->fetchAll(PDO::FETCH_COLUMN);
                $insertCharge = $db->prepare("INSERT INTO charges (inmate_id, charge_description, charge_type, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
                foreach ($details['charges'] as $chargeText) {
                    $found = false;
                    foreach ($existingCharges as $ec) {
                        if (stripos($ec, $chargeText) !== false || stripos($chargeText, $ec) !== false) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
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

            // Save to inmate_case_details if table exists
            try {
                $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inmate_case_details'")->fetchColumn();
                if ($check) {
                    $charges_json = json_encode(array_map(function ($c) { return ['description' => $c]; }, $details['charges']));
                    $stmtCd = $db->prepare("
                        INSERT INTO inmate_case_details (inmate_id, docket_number, disposition, sentence, charges_json, court_dates_json, bonds_json, scrape_time)
                        VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
                        ON CONFLICT(inmate_id, docket_number) DO UPDATE SET
                            charges_json = COALESCE(excluded.charges_json, inmate_case_details.charges_json),
                            last_updated = CURRENT_TIMESTAMP
                    ");
                    $stmtCd->execute([
                        $dkt,
                        $dkt,
                        null,
                        null,
                        $charges_json,
                        json_encode([]),
                        json_encode([['amount' => $details['bond_amount']]])
                    ]);
                }
            } catch (Exception $e) {
                // ignore
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'DB save error: ' . $e->getMessage()]);
            exit;
        }

        // Return parsed details
        echo json_encode(['success' => true, 'details' => $details, 'message' => 'Fetched and saved details']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$chargeFilter = $_GET['charge_type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$timeRange = $_GET['time_range'] ?? '30';

// Build WHERE clause for filters
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(i.name LIKE ? OR i.inmate_id LIKE ? OR i.le_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter === 'active') {
    $whereConditions[] = "i.in_jail = 1";
} elseif ($statusFilter === 'released') {
    $whereConditions[] = "i.in_jail = 0";
}

if ($chargeFilter !== 'all') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM charges c WHERE c.inmate_id = i.inmate_id AND c.charge_type = ?)";
    $params[] = ucfirst($chargeFilter);
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// ===== COMPREHENSIVE STATISTICS =====

// Core Stats
$totalInmates = $db->query("SELECT COUNT(*) FROM inmates")->fetchColumn();
$activeInmates = $db->query("SELECT COUNT(*) FROM inmates WHERE in_jail = 1")->fetchColumn();
$releasedInmates = $db->query("SELECT COUNT(*) FROM inmates WHERE in_jail = 0")->fetchColumn();
$totalCharges = $db->query("SELECT COUNT(*) FROM charges")->fetchColumn();

// Recent Activity (Last 24 hours, 7 days, 30 days)
$last24Hours = $db->query("
    SELECT COUNT(*) FROM inmates 
    WHERE booking_date >= datetime('now', '-1 day')
")->fetchColumn();

$last7Days = $db->query("
    SELECT COUNT(*) FROM inmates 
    WHERE booking_date >= datetime('now', '-7 days')
")->fetchColumn();

$last30Days = $db->query("
    SELECT COUNT(*) FROM inmates 
    WHERE booking_date >= datetime('now', '-30 days')
")->fetchColumn();

// Bond Statistics
$allBonds = $db->query("
    SELECT bond_amount FROM inmates 
    WHERE bond_amount IS NOT NULL AND bond_amount != ''
")->fetchAll(PDO::FETCH_COLUMN);

$numericBonds = [];
foreach ($allBonds as $bondStr) {
    if (preg_match('/\$?([0-9,.]+)/', $bondStr, $matches)) {
        $numericPart = str_replace(',', '', $matches[1]);
        if (is_numeric($numericPart)) {
            $numericBonds[] = (float)$numericPart;
        }
    }
}

$topBond = !empty($numericBonds) ? max($numericBonds) : 0;
$avgBond = !empty($numericBonds) ? array_sum($numericBonds) / count($numericBonds) : 0;
$totalBondValue = !empty($numericBonds) ? array_sum($numericBonds) : 0;

// Charge Type Breakdown
$chargeBreakdown = $db->query("
    SELECT 
        CASE 
            WHEN charge_type IS NULL OR charge_type = '' THEN 'Unknown'
            ELSE charge_type 
        END as type,
        COUNT(*) as count
    FROM charges
    GROUP BY type
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Top 15 Charge Descriptions
$topCharges = $db->query("
    SELECT charge_description, COUNT(*) as count
    FROM charges
    WHERE charge_description IS NOT NULL AND charge_description != ''
    GROUP BY charge_description
    ORDER BY count DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$maxChargeCount = !empty($topCharges) ? $topCharges[0]['count'] : 1;

// Demographics
$genderStats = $db->query("
    SELECT sex, COUNT(*) as count 
    FROM inmates 
    WHERE sex IS NOT NULL AND sex != ''
    GROUP BY sex
")->fetchAll(PDO::FETCH_ASSOC);

$raceStats = $db->query("
    SELECT race, COUNT(*) as count 
    FROM inmates 
    WHERE race IS NOT NULL AND race != ''
    GROUP BY race
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Age Distribution
$ageStats = $db->query("
    SELECT 
        CASE 
            WHEN age < 18 THEN 'Under 18'
            WHEN age BETWEEN 18 AND 25 THEN '18-25'
            WHEN age BETWEEN 26 AND 35 THEN '26-35'
            WHEN age BETWEEN 36 AND 50 THEN '36-50'
            WHEN age > 50 THEN 'Over 50'
            ELSE 'Unknown'
        END as age_range,
        COUNT(*) as count
    FROM inmates
    WHERE age IS NOT NULL
    GROUP BY age_range
    ORDER BY 
        CASE age_range
            WHEN 'Under 18' THEN 1
            WHEN '18-25' THEN 2
            WHEN '26-35' THEN 3
            WHEN '36-50' THEN 4
            WHEN 'Over 50' THEN 5
            ELSE 6
        END
")->fetchAll(PDO::FETCH_ASSOC);

// Booking Trends (Last 30 Days)
$bookingTrends = $db->query("
    SELECT 
        DATE(booking_date) as date, 
        COUNT(*) as bookings,
        SUM(CASE WHEN in_jail = 0 THEN 1 ELSE 0 END) as releases
    FROM inmates
    WHERE booking_date >= date('now', '-30 days')
    GROUP BY DATE(booking_date)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$trendDates = array_column($bookingTrends, 'date');
$trendBookings = array_column($bookingTrends, 'bookings');
$trendReleases = array_column($bookingTrends, 'releases');

// High-Risk Indicators (for law enforcement tracking)
$violentCrimes = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM charges
    WHERE charge_description LIKE '%MURDER%'
       OR charge_description LIKE '%ASSAULT AGGRAVATED%'
       OR charge_description LIKE '%RAPE%'
       OR charge_description LIKE '%ARMED ROBBERY%'
       OR charge_description LIKE '%KIDNAPPING%'
")->fetchColumn();

$repeatOffenders = $db->query("
    SELECT COUNT(*) FROM (
        SELECT inmate_id, COUNT(*) as charge_count
        FROM charges
        GROUP BY inmate_id
        HAVING charge_count >= 3
    )
")->fetchColumn();

$domesticViolence = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM charges
    WHERE charge_description LIKE '%FAMILY VIOLENCE%'
       OR charge_description LIKE '%DOMESTIC%'
       OR charge_description LIKE '%BATTERY%FAMILY%'
")->fetchColumn();

// ===== RECENT INMATES WITH PAGINATION =====

// Pagination settings
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Count total records matching filters
$countSql = "
    SELECT COUNT(DISTINCT i.id) as total
    FROM inmates i
    LEFT JOIN charges c ON i.inmate_id = c.inmate_id
    $whereClause
";
$countStmt = $db->prepare($countSql);
if (!empty($params)) {
    foreach ($params as $k => $p) {
        // bind 1-based
        $countStmt->bindValue($k + 1, $p);
    }
}
$countStmt->execute();
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch records for current page
$sql = "
    SELECT i.*, 
           GROUP_CONCAT(c.charge_description, '; ') as all_charges,
           COUNT(c.id) as charge_count
    FROM inmates i
    LEFT JOIN charges c ON i.inmate_id = c.inmate_id
    $whereClause
    GROUP BY i.id
    ORDER BY i.booking_date DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);

// bind existing filter params first
$bindIndex = 1;
if (!empty($params)) {
    foreach ($params as $p) {
        $stmt->bindValue($bindIndex, $p);
        $bindIndex++;
    }
}
// bind limit and offset
$stmt->bindValue($bindIndex, $perPage, PDO::PARAM_INT);
$bindIndex++;
$stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);

$stmt->execute();
$recentInmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to merge current GET params for pagination links (preserve filters/search)
function buildPageUrl($pageNum) {
    $qs = $_GET;
    $qs['page'] = $pageNum;
    return '?' . http_build_query($qs);
}

// Store recent inmates in a JS-accessible variable
$recentInmatesJS = json_encode(array_column($recentInmates, null, 'inmate_id'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Community Safety Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --color-primary: #06b6d4;
            --color-primary-dark: #0891b2;
            --color-secondary: #3b82f6;
            --color-success: #10b981;
            --color-warning: #f59e0b;
            --color-danger: #ef4444;
            --color-bg: #0f1419;
            --color-bg-secondary: #1a1f2e;
            --color-bg-tertiary: #242b3d;
            --color-text: #e5e7eb;
            --color-text-secondary: #9ca3af;
            --color-border: #374151;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            line-height: 1.6;
        }

        /* Navigation */
        .navbar {
            background: var(--color-bg-secondary);
            border-bottom: 1px solid var(--color-border);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-primary);
            text-decoration: none;
        }

        .navbar-brand i {
            font-size: 1.75rem;
        }

        .navbar-menu {
            display: flex;
            gap: 2rem;
            list-style: none;
        }

        .navbar-menu a {
            color: var(--color-text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-menu a:hover,
        .navbar-menu a.active {
            color: var(--color-primary);
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--color-text-secondary);
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Hero Stats */
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: transform 0.2s, border-color 0.2s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--stat-color), transparent);
        }

        .stat-card.primary { --stat-color: var(--color-primary); }
        .stat-card.success { --stat-color: var(--color-success); }
        .stat-card.warning { --stat-color: var(--color-warning); }
        .stat-card.danger { --stat-color: var(--color-danger); }
        .stat-card.secondary { --stat-color: var(--color-secondary); }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--stat-color);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-title {
            font-size: 0.875rem;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(6, 182, 212, 0.1);
            color: var(--stat-color);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--color-text);
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-change.positive {
            color: var(--color-success);
        }

        .stat-change.negative {
            color: var(--color-danger);
        }

        /* Main Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        /* Sidebar */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .sidebar-section {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--color-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-box {
            width: 100%;
            padding: 0.75rem;
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            color: var(--color-text);
            font-size: 0.9rem;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--color-primary);
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--color-text-secondary);
        }

        .filter-select {
            width: 100%;
            padding: 0.625rem;
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text);
            font-size: 0.875rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-primary {
            background: var(--color-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--color-primary-dark);
        }

        .btn-block {
            width: 100%;
        }

        /* Quick Links */
        .quick-links {
            list-style: none;
        }

        .quick-links li {
            margin-bottom: 0.75rem;
        }

        .quick-links a {
            color: var(--color-text-secondary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            transition: color 0.2s;
        }

        .quick-links a:hover {
            color: var(--color-primary);
        }

        /* Content Section */
        .content-section {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--color-border);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        canvas {
            max-height: 300px;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--color-bg-tertiary);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--color-border);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--color-border);
            font-size: 0.9rem;
        }

        tr:hover {
            background: var(--color-bg-tertiary);
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--color-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-released {
            background: rgba(59, 130, 246, 0.1);
            color: var(--color-secondary);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .badge-felony {
            background: rgba(239, 68, 68, 0.1);
            color: var(--color-danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .badge-misdemeanor {
            background: rgba(245, 158, 11, 0.1);
            color: var(--color-warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Crime Bars */
        .crime-chart {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .crime-item {
            display: grid;
            grid-template-columns: 2fr 3fr auto;
            gap: 1rem;
            align-items: center;
        }

        .crime-label {
            font-size: 0.875rem;
            color: var(--color-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .crime-bar-container {
            background: var(--color-bg-tertiary);
            height: 32px;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }

        .crime-bar {
            height: 100%;
            border-radius: 6px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            padding: 0 0.75rem;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .crime-count {
            font-weight: 700;
            color: var(--color-text);
            min-width: 40px;
            text-align: right;
        }

        /* Alert Box */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--color-secondary);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: var(--color-warning);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--color-danger);
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: flex-end;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            text-decoration: none;
            color: var(--color-text);
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            font-weight: 600;
        }

        .pagination a:hover {
            border-color: var(--color-primary);
            color: var(--color-primary);
        }

        .pagination .active {
            background: linear-gradient(90deg, rgba(6,182,212,0.12), rgba(59,130,246,0.08));
            border-color: var(--color-primary);
            color: var(--color-primary);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }

        .modal {
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            max-width: 800px;
            width: 100%;
            padding: 1.5rem;
            color: var(--color-text);
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .modal-title {
            font-size: 1.125rem;
            font-weight: 700;
        }

        .modal-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .modal-row {
            margin-bottom: 0.5rem;
        }

        .modal-label {
            font-size: 0.8rem;
            color: var(--color-text-secondary);
            margin-bottom: 0.25rem;
        }

        .modal-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--color-text);
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--color-text-secondary);
            font-size: 1.25rem;
            cursor: pointer;
        }

        /* Footer */
        .footer {
            background: var(--color-bg-secondary);
            border-top: 1px solid var(--color-border);
            margin-top: 4rem;
            padding: 3rem 0 2rem;
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
        }

        .footer-section h3 {
            color: var(--color-primary);
            margin-bottom: 1rem;
            font-size: 1.125rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: var(--color-text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: var(--color-primary);
        }

        .footer-bottom {
            max-width: 1400px;
            margin: 2rem auto 0;
            padding: 2rem 2rem 0;
            border-top: 1px solid var(--color-border);
            text-align: center;
            color: var(--color-text-secondary);
            font-size: 0.875rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: -1;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .navbar-menu {
                display: none;
            }

            .modal-body {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .hero-stats {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 2rem;
            }

            .crime-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            table {
                font-size: 0.8rem;
            }

            th, td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
                <?php include 'nav_dropdown.php'; // Include the new dropdown menu ?>
                
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span>Inmate360</span>
            </a>
            <ul class="navbar-menu">
                <li><a href="#" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="#inmates"><i class="fas fa-users"></i> Inmates</a></li>
                <li><a href="#resources"><i class="fas fa-hands-helping"></i> Resources</a></li>
                <li><a href="#reports"><i class="fas fa-chart-bar"></i> Reports</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-name">Community User</div>
                    <div class="user-role">Public Access</div>
                </div>
                <i class="fas fa-user-circle" style="font-size: 2rem; color: var(--color-primary);"></i>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Important Community Notice -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle" style="font-size: 1.5rem;"></i>
            <div>
                <strong>Community Safety Resource:</strong> This platform provides real-time jail data to help protect victims of abuse and assist law enforcement. If you're a victim of domestic viole[...]
            </div>
        </div>

        <!-- Hero Stats -->
        <div class="hero-stats">
            <div class="stat-card primary">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Total Inmates</div>
                        <div class="stat-value"><?php echo number_format($totalInmates); ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <?php echo number_format($last24Hours); ?> in last 24h
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Currently Active</div>
                        <div class="stat-value"><?php echo number_format($activeInmates); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-user-clock"></i>
                            In custody now
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Total Bond Value</div>
                        <div class="stat-value">$<?php echo number_format($totalBondValue / 1000000, 1); ?>M</div>
                        <div class="stat-change">
                            <i class="fas fa-gavel"></i>
                            Avg: $<?php echo number_format($avgBond); ?>
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Violent Crimes</div>
                        <div class="stat-value"><?php echo number_format($violentCrimes); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-exclamation-triangle"></i>
                            High priority tracking
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card secondary">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Domestic Violence</div>
                        <div class="stat-value"><?php echo number_format($domesticViolence); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-home"></i>
                            Family violence cases
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-house-damage"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Repeat Offenders</div>
                        <div class="stat-value"><?php echo number_format($repeatOffenders); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-redo"></i>
                            3+ charges
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card primary">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Last 30 Days</div>
                        <div class="stat-value"><?php echo number_format($last30Days); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-calendar-alt"></i>
                            Recent bookings
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card secondary">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Total Charges</div>
                        <div class="stat-value"><?php echo number_format($totalCharges); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-file-alt"></i>
                            All records
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Sidebar -->
            <aside class="sidebar">
                <!-- Search & Filters -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">
                        <i class="fas fa-search"></i>
                        Search & Filter
                    </h3>
                    <form method="GET" action="">
                        <input 
                            type="text" 
                            name="search" 
                            class="search-box" 
                            placeholder="Search by name, ID, or LE#..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                        
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="released" <?php echo $statusFilter === 'released' ? 'selected' : ''; ?>>Released</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label">Charge Type</label>
                            <select name="charge_type" class="filter-select">
                                <option value="all" <?php echo $chargeFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="felony" <?php echo $chargeFilter === 'felony' ? 'selected' : ''; ?>>Felony</option>
                                <option value="misdemeanor" <?php echo $chargeFilter === 'misdemeanor' ? 'selected' : ''; ?>>Misdemeanor</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </form>
                </div>

                <!-- Victim Resources -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">
                        <i class="fas fa-heart"></i>
                        Victim Resources
                    </h3>
                    <ul class="quick-links">
                        <li><a href="tel:911"><i class="fas fa-phone-alt"></i> Emergency: 911</a></li>
                        <li><a href="tel:18007997233"><i class="fas fa-headset"></i> National DV Hotline</a></li>
                        <li><a href="#"><i class="fas fa-shield-alt"></i> Protective Orders</a></li>
                        <li><a href="#"><i class="fas fa-home"></i> Safe Housing</a></li>
                        <li><a href="#"><i class="fas fa-balance-scale"></i> Legal Aid</a></li>
                        <li><a href="#"><i class="fas fa-user-friends"></i> Support Groups</a></li>
                    </ul>
                </div>

                <!-- Law Enforcement Tools -->
                <div class="sidebar-section">
                    <h3 class="sidebar-title">
                        <i class="fas fa-user-shield"></i>
                        LE Tools
                    </h3>
                    <ul class="quick-links">
                        <li><a href="#"><i class="fas fa-bell"></i> Set Alerts</a></li>
                        <li><a href="#"><i class="fas fa-download"></i> Export Data</a></li>
                        <li><a href="#"><i class="fas fa-chart-pie"></i> Analytics</a></li>
                        <li><a href="#"><i class="fas fa-file-pdf"></i> Reports</a></li>
                    </ul>
                </div>
            </aside>

            <!-- Main Content -->
            <main>
                <!-- Charts Section -->
                <div class="charts-grid">
                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-chart-line"></i>
                                Booking Trends (30 Days)
                            </h2>
                        </div>
                        <canvas id="bookingTrendsChart"></canvas>
                    </div>

                    <div class="content-section">
                        <div class="section-header">
                            <h2 class="section-title">
                                <i class="fas fa-chart-pie"></i>
                                Charge Distribution
                            </h2>
                        </div>
                        <canvas id="chargeTypeChart"></canvas>
                    </div>
                </div>

                <!-- Top Charges -->
                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-list-ol"></i>
                            Top 15 Charges
                        </h2>
                    </div>
                    <div class="crime-chart">
                        <?php 
                        $colors = [
                            '#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444',
                            '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1',
                            '#84cc16', '#eab308', '#22c55e', '#a855f7', '#fb923c'
                        ];
                        foreach ($topCharges as $index => $crime): 
                            $progress = ($crime['count'] / $maxChargeCount) * 100;
                            $color = $colors[$index % count($colors)];
                        ?>
                            <div class="crime-item">
                                <div class="crime-label" title="<?php echo htmlspecialchars($crime['charge_description']); ?>">
                                    <?php echo htmlspecialchars($crime['charge_description']); ?>
                                </div>
                                <div class="crime-bar-container">
                                    <div class="crime-bar" style="width: <?php echo $progress; ?>%; background: <?php echo $color; ?>;">
                                        <?php if ($progress > 20): ?>
                                            <?php echo $crime['count']; ?> cases
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="crime-count"><?php echo $crime['count']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Inmates Table -->
                <div class="content-section" id="inmates">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-users"></i>
                            Recent Inmates
                            <?php if (!empty($search) || $statusFilter !== 'all' || $chargeFilter !== 'all'): ?>
                                (Filtered)
                            <?php endif; ?>
                        </h2>
                        <span class="badge badge-active"><?php echo count($recentInmates); ?> Records</span>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID / LE#</th>
                                    <th>Name</th>
                                    <th>Age</th>
                                    <th>Booking Date</th>
                                    <th>Status</th>
                                    <th>Charges</th>
                                    <th>Bond</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentInmates as $inmate): ?>
                                    <tr>
                                        <td>
                                            <div style="font-family: monospace; font-size: 0.85rem;">
                                                <?php echo htmlspecialchars($inmate['inmate_id']); ?>
                                                <?php if (!empty($inmate['le_number'])): ?>
                                                    <br><span style="color: var(--color-text-secondary);">LE: <?php echo htmlspecialchars($inmate['le_number']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($inmate['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($inmate['age'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($inmate['booking_date'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($inmate['in_jail']): ?>
                                                <span class="badge badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-released">Released</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($inmate['all_charges'] ?? 'N/A'); ?>">
                                                <?php echo htmlspecialchars($inmate['all_charges'] ?? 'N/A'); ?>
                                            </div>
                                            <small style="color: var(--color-text-secondary);">(<?php echo $inmate['charge_count']; ?> total)</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($inmate['bond_amount'] ?? 'N/A'); ?></td>
                                        <td>
                                            <button class="btn btn-primary view-btn" data-inmate-id="<?php echo htmlspecialchars($inmate['inmate_id']); ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <div class="pagination" role="navigation" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo buildPageUrl($page - 1); ?>" title="Previous">&laquo; Prev</a>
                            <?php else: ?>
                                <span style="opacity: 0.5;">&laquo; Prev</span>
                            <?php endif; ?>

                            <?php
                            // Show page numbers: keep it compact if many pages
                            $start = max(1, $page - 3);
                            $end = min($totalPages, $page + 3);
                            if ($start > 1) {
                                echo '<a href="' . buildPageUrl(1) . '">1</a>';
                                if ($start > 2) echo '<span style="margin:0 4px;">...</span>';
                            }
                            for ($p = $start; $p <= $end; $p++): 
                                if ($p == $page):
                            ?>
                                    <span class="active"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo buildPageUrl($p); ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor;
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span style="margin:0 4px;">...</span>';
                                echo '<a href="' . buildPageUrl($totalPages) . '">' . $totalPages . '</a>';
                            }
                            ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo buildPageUrl($page + 1); ?>" title="Next">Next &raquo;</a>
                            <?php else: ?>
                                <span style="opacity: 0.5;">Next &raquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal-overlay" id="modalOverlay" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <div class="modal-header">
                <div>
                    <div id="modalTitle" class="modal-title">Inmate Details</div>
                    <div style="font-size:0.85rem; color: var(--color-text-secondary);" id="modalSubTitle"></div>
                </div>
                <button class="modal-close" id="modalClose" aria-label="Close modal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div>
                    <div class="modal-row">
                        <div class="modal-label">Inmate ID</div>
                        <div class="modal-value" id="m-inmate-id"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">LE Number</div>
                        <div class="modal-value" id="m-le-number"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Age</div>
                        <div class="modal-value" id="m-age"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Sex</div>
                        <div class="modal-value" id="m-sex"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Race</div>
                        <div class="modal-value" id="m-race"></div>
                    </div>
                </div>
                <div>
                    <div class="modal-row">
                        <div class="modal-label">Booking Date</div>
                        <div class="modal-value" id="m-booking-date"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Status</div>
                        <div class="modal-value" id="m-status"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Bond Amount</div>
                        <div class="modal-value" id="m-bond"></div>
                    </div>
                    <div class="modal-row">
                        <div class="modal-label">Charges</div>
                        <div class="modal-value" id="m-charges" style="white-space: pre-wrap; max-height:160px; overflow:auto;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer" id="resources">
        <div class="footer-content">
            <div class="footer-section">
                <h3><i class="fas fa-heart"></i> Victim Support</h3>
                <ul class="footer-links">
                    <li><a href="tel:18007997233">National DV Hotline: 1-800-799-7233</a></li>
                    <li><a href="tel:18006564673">National Sexual Assault Hotline</a></li>
                    <li><a href="#">Local Shelters & Resources</a></li>
                    <li><a href="#">Victim Compensation Program</a></li>
                    <li><a href="#">Legal Aid Services</a></li>
                    <li><a href="#">Counseling & Support Groups</a></li>
                </ul>
            </div>

            <div class="footer-section">
                <h3><i class="fas fa-shield-alt"></i> Law Enforcement</h3>
                <ul class="footer-links">
                    <li><a href="#">Clayton County Sheriff</a></li>
                    <li><a href="#">Submit a Tip</a></li>
                    <li><a href="#">Warrant Information</a></li>
                    <li><a href="#">Officer Resources</a></li>
                    <li><a href="#">Training Materials</a></li>
                    <li><a href="#">Data Request Form</a></li>
                </ul>
            </div>

            <div class="footer-section">
                <h3><i class="fas fa-info-circle"></i> About</h3>
                <ul class="footer-links">
                    <li><a href="#">About Inmate360</a></li>
                    <li><a href="#">How to Use This Platform</a></li>
                    <li><a href="#">Data Sources</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Contact Us</a></li>
                </ul>
            </div>

            <div class="footer-section">
                <h3><i class="fas fa-hands-helping"></i> Community</h3>
                <ul class="footer-links">
                    <li><a href="#">Community Organizations</a></li>
                    <li><a href="#">Volunteer Opportunities</a></li>
                    <li><a href="#">Educational Programs</a></li>
                    <li><a href="#">Prevention Resources</a></li>
                    <li><a href="#">Reentry Support</a></li>
                    <li><a href="#">Family Services</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Inmate360. A Community Safety Initiative for Clayton County, Georgia.</p>
            <p style="margin-top: 0.5rem; font-size: 0.8rem;">
                Data updated in real-time. For emergencies, always call 911. This platform is designed to protect victims and assist law enforcement.
            </p>
        </div>
    </footer>

    <script>
        const allInmatesData = <?php echo $recentInmatesJS; ?>;

        // Booking Trends Chart
        const bookingCtx = document.getElementById('bookingTrendsChart').getContext('2d');
        new Chart(bookingCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendDates); ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?php echo json_encode($trendBookings); ?>,
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Releases',
                    data: <?php echo json_encode($trendReleases); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#e5e7eb',
                            font: { size: 12, weight: 600 }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#9ca3af' },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { 
                            color: '#9ca3af',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { color: '#374151' }
                    }
                }
            }
        });

        // Charge Type Distribution Chart
        const chargeCtx = document.getElementById('chargeTypeChart').getContext('2d');
        new Chart(chargeCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($chargeBreakdown, 'type')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($chargeBreakdown, 'count')); ?>,
                    backgroundColor: [
                        '#3b82f6',
                        '#06b6d4',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                        '#8b5cf6'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#e5e7eb',
                            font: { size: 12, weight: 600 },
                            padding: 15
                        }
                    }
                }
            }
        });

        // Modal support
        const modalOverlay = document.getElementById('modalOverlay');
        const modalClose = document.getElementById('modalClose');

        function openModal() {
            modalOverlay.style.display = 'flex';
            modalOverlay.setAttribute('aria-hidden', 'false');
            // focus close button for accessibility
            modalClose.focus();
        }
        function closeModal() {
            modalOverlay.style.display = 'none';
            modalOverlay.setAttribute('aria-hidden', 'true');
        }

        modalClose.addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) closeModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalOverlay.style.display === 'flex') {
                closeModal();
            }
        });

        // Helper to set modal fields
        function setModalField(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = value ?? 'N/A';
        }

        // Attach click listeners to view buttons and fetch remote details
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const inmateId = this.getAttribute('data-inmate-id');
                if (!inmateId || !allInmatesData[inmateId]) {
                    console.error('Inmate data not found for ID:', inmateId);
                    return;
                }
                const inmate = allInmatesData[inmateId];

                // Populate basic fields immediately
                document.getElementById('modalTitle').textContent = inmate.name || 'Inmate Details';
                let subtitle = '';
                if (inmate.inmate_id) subtitle += 'ID: ' + inmate.inmate_id;
                if (inmate.le_number) subtitle += (subtitle ? ' | ' : '') + 'LE#: ' + inmate.le_number;
                document.getElementById('modalSubTitle').textContent = subtitle;

                // Loading state
                setModalField('m-inmate-id', inmate.inmate_id || 'N/A');
                setModalField('m-le-number', inmate.le_number || 'N/A');
                setModalField('m-age', inmate.age || 'Loading…');
                setModalField('m-sex', 'Loading…');
                setModalField('m-race', 'Loading…');
                setModalField('m-booking-date', 'Loading…');
                setModalField('m-status', inmate.in_jail ? 'Active - In Custody' : 'Released');
                setModalField('m-bond', inmate.bond_amount || 'Loading…');
                setModalField('m-charges', 'Loading charges…');

                openModal();

                // POST to this same file to fetch and save details
                const form = new FormData();
                form.append('action', 'fetch_inmate_details');
                const dkt = inmate.docket_number || inmate.inmate_id || '';
                const le = inmate.le_number || '';
                form.append('dkt', dkt);
                form.append('le', le);

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                }).then(r => r.json()).then(json => {
                    if (!json || !json.success) {
                        console.error('Fetch failed', json);
                        setModalField('m-charges', 'Failed to fetch details: ' + (json && json.message ? json.message : 'unknown'));
                        // keep basic fields
                        setModalField('m-age', inmate.age || 'N/A');
                        setModalField('m-sex', inmate.sex || 'N/A');
                        setModalField('m-race', inmate.race || 'N/A');
                        setModalField('m-booking-date', inmate.booking_date || 'N/A');
                        setModalField('m-bond', inmate.bond_amount || 'N/A');
                        return;
                    }

                    const details = json.details || {};

                    setModalField('m-inmate-id', details.inmate_id || dkt || inmate.inmate_id || 'N/A');
                    setModalField('m-le-number', details.le_number || le || inmate.le_number || 'N/A');
                    setModalField('m-age', details.age || inmate.age || 'N/A');
                    setModalField('m-sex', details.sex || 'N/A');
                    setModalField('m-race', details.race || 'N/A');
                    setModalField('m-booking-date', details.booking_date || inmate.booking_date || 'N/A');
                    setModalField('m-status', (inmate.in_jail ? 'Active - In Custody' : 'Released'));
                    setModalField('m-bond', details.bond_amount || inmate.bond_amount || 'N/A');

                    if (Array.isArray(details.charges) && details.charges.length > 0) {
                        setModalField('m-charges', details.charges.join('\n'));
                    } else {
                        setModalField('m-charges', inmate.all_charges || 'No charges found');
                    }
                }).catch(err => {
                    console.error('Fetch error', err);
                    setModalField('m-charges', 'Error fetching details');
                    setModalField('m-age', inmate.age || 'N/A');
                    setModalField('m-sex', inmate.sex || 'N/A');
                    setModalField('m-race', inmate.race || 'N/A');
                    setModalField('m-booking-date', inmate.booking_date || 'N/A');
                    setModalField('m-bond', inmate.bond_amount || 'N/A');
                });
            });
        });
    </script>
</body>
</html>