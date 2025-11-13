<?php
/**
 * Inmate360 Professional Dashboard
 * Community Safety & Law Enforcement Analytics Platform
 * Clayton County, Georgia
 * 
 * VERSION: v2.4 - Authentication & Dropdown Menu
 * UPDATED: 2025-11-13 07:59:31
 * Features: User authentication, dropdown navigation, improved security
 */

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'invite_gate.php';
// Check if user is registered and logged in

checkInviteAccess();

// Get user information from session
$userName = $_SESSION['first_name'] ?? 'User';
$userEmail = $_SESSION['email'] ?? '';
$userTier = $_SESSION['tier'] ?? 'community';

// Map tier to display name
$tierDisplay = [
    'community' => 'Community Access',
    'beta' => 'Beta Tester',
    'law_enforcement' => 'Law Enforcement',
    'admin' => 'Administrator'
];
$userRole = $tierDisplay[$userTier] ?? 'Community Access';

// Initialize database
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * DATA VALIDATION HELPER FUNCTIONS
 */

function isInvalidPlaceholder($value) {
    $value = trim($value);
    $invalid = [
        'Inmate details',
        '*IN JAIL*',
        'IN JAIL',
        'UNKNOWN',
        'NAME',
        'Name Type',
        'N/A',
        'NA',
        '',
        'null'
    ];
    
    return in_array($value, $invalid, true) || empty($value);
}

function isValidInmateId($id) {
    $id = trim($id);
    return !empty($id) && is_numeric($id) && $id > 0 && strlen($id) <= 10;
}

function isValidName($name) {
    $name = trim($name);
    return !empty($name) && strlen($name) > 2 && strlen($name) < 100 && !isInvalidPlaceholder($name);
}

function isValidAge($age) {
    return is_numeric($age) && $age > 0 && $age < 130;
}

function parseBookingDate($dateStr) {
    if (empty($dateStr) || isInvalidPlaceholder($dateStr)) {
        return null;
    }
    
    $timestamp = strtotime($dateStr);
    if ($timestamp === false) {
        return null;
    }
    
    $dt = new DateTime('@' . $timestamp);
    return [
        'date' => $dt->format('Y-m-d'),
        'time' => $dt->format('H:i:s')
    ];
}

function extractRealName($rawName) {
    $rawName = trim($rawName);
    
    if (isInvalidPlaceholder($rawName)) {
        return null;
    }
    
    $rawName = preg_replace('/\s+/', ' ', $rawName);
    
    if (strpos($rawName, ',') !== false) {
        $parts = explode(',', $rawName);
        if (count($parts) >= 2) {
            $lastName = trim($parts[0]);
            $firstName = trim($parts[1]);
            
            if (!empty($lastName) && !empty($firstName) && strlen($lastName) > 1 && strlen($firstName) > 1) {
                return "$firstName $lastName";
            }
        }
    }
    
    $parts = explode(' ', $rawName);
    if (count($parts) >= 2) {
        $combinedName = implode(' ', $parts);
        if (isValidName($combinedName)) {
            return $combinedName;
        }
    }
    
    if (strlen($rawName) > 2 && strlen($rawName) < 50) {
        return $rawName;
    }
    
    return null;
}

/**
 * AJAX ENDPOINT: fetch_inmate_details
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetch_inmate_details') {
    header('Content-Type: application/json; charset=utf-8');

    $dkt = trim($_POST['dkt'] ?? '');
    $le  = trim($_POST['le'] ?? '');

    if (empty($dkt) || !isValidInmateId($dkt)) {
        echo json_encode(['success' => false, 'message' => 'Invalid docket number']);
        exit;
    }

    $remote = 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj205r.pgm?dkt=' . urlencode($dkt);
    if (!empty($le)) {
        $remote .= '&le=' . urlencode($le);
    }

    try {
        $html = null;
        $maxRetries = 3;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($remote);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Inmate360/2.4 (Production)',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => 'gzip, deflate',
            ]);
            
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode === 200 && !empty($html)) {
                break;
            }
            
            if ($attempt < $maxRetries) {
                sleep(2 * $attempt);
            }
        }

        if (!$html || empty($html)) {
            echo json_encode(['success' => false, 'message' => 'Failed to fetch remote page']);
            exit;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $details = [
            'inmate_id'    => $dkt,
            'le_number'    => $le ?: null,
            'name'         => null,
            'age'          => null,
            'sex'          => null,
            'race'         => null,
            'height'       => null,
            'weight'       => null,
            'hair_color'   => null,
            'eye_color'    => null,
            'booking_date' => null,
            'booking_time' => null,
            'release_date' => null,
            'release_time' => null,
            'bond_amount'  => null,
            'arresting_agency' => null,
            'charges'      => []
        ];

        $rows = $xpath->query('//tr');
        $charge_rows = [];
        
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;

            $label = trim($cells->item(0)->textContent);
            $value = trim($cells->item(1)->textContent);
            
            if (empty($label) || empty($value)) continue;
            if (isInvalidPlaceholder($value)) continue;

            if (preg_match('/name/i', $label) && empty($details['name'])) {
                $cleanName = extractRealName($value);
                if ($cleanName) {
                    $details['name'] = $cleanName;
                }
            }
            elseif (preg_match('/\bage\b/i', $label) && isValidAge($value)) {
                $details['age'] = (int)$value;
            }
            elseif (preg_match('/sex|gender/i', $label) && strlen(trim($value)) === 1) {
                $details['sex'] = strtoupper(substr(trim($value), 0, 1));
            }
            elseif (preg_match('/race|ethnicity/i', $label) && strlen($value) > 1 && strlen($value) < 50) {
                $details['race'] = $value;
            }
            elseif (preg_match('/height/i', $label) && !isInvalidPlaceholder($value)) {
                $details['height'] = $value;
            }
            elseif (preg_match('/weight/i', $label) && !isInvalidPlaceholder($value)) {
                $details['weight'] = $value;
            }
            elseif (preg_match('/hair\s*color|hair/i', $label) && !isInvalidPlaceholder($value)) {
                $details['hair_color'] = $value;
            }
            elseif (preg_match('/eye\s*color|eyes/i', $label) && !isInvalidPlaceholder($value)) {
                $details['eye_color'] = $value;
            }
            elseif (preg_match('/booking.*date|booked/i', $label)) {
                $dateInfo = parseBookingDate($value);
                if ($dateInfo) {
                    $details['booking_date'] = $dateInfo['date'];
                    $details['booking_time'] = $dateInfo['time'];
                }
            }
            elseif (preg_match('/release.*date|released/i', $label)) {
                $dateInfo = parseBookingDate($value);
                if ($dateInfo) {
                    $details['release_date'] = $dateInfo['date'];
                    $details['release_time'] = $dateInfo['time'];
                }
            }
            elseif (preg_match('/bond/i', $label) && preg_match('/\$|[0-9]/', $value)) {
                $details['bond_amount'] = $value;
            }
            elseif (preg_match('/arresting.*agency|agency|arresting/i', $label)) {
                $details['arresting_agency'] = $value;
            }
            elseif (preg_match('/charge|offense|docket.*desc/i', $label) && strlen($value) > 3) {
                $charge_rows[] = $value;
            }
        }

        $bodyText = preg_replace('/\s+/', ' ', $xpath->query('//body')->item(0)->textContent ?? '');
        
        if (preg_match_all('/(?:Charge|Offense|Docket)[\s:\-]+([^\n;.]+)/i', $bodyText, $matches)) {
            foreach ($matches[1] as $charge) {
                $charge = trim($charge);
                if (!empty($charge) && strlen($charge) > 3 && strlen($charge) < 300) {
                    $charge_rows[] = $charge;
                }
            }
        }
        
        $uniqueCharges = [];
        foreach ($charge_rows as $chargeText) {
            $chargeText = trim($chargeText);
            if (!empty($chargeText) && strlen($chargeText) > 3 && strlen($chargeText) < 300) {
                $isDuplicate = false;
                foreach ($uniqueCharges as $existing) {
                    if (stripos($existing, $chargeText) !== false || 
                        stripos($chargeText, $existing) !== false) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if (!$isDuplicate) {
                    $uniqueCharges[] = $chargeText;
                }
            }
        }
        $details['charges'] = $uniqueCharges;

        $chargeStmt = $db->prepare("SELECT charge_description FROM charges WHERE inmate_id = ? ORDER BY created_at DESC");
        $chargeStmt->execute([$dkt]);
        $dbCharges = $chargeStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $allCharges = [];
        foreach ($dbCharges as $dbCharge) {
            if (!empty(trim($dbCharge))) {
                $allCharges[] = htmlspecialchars($dbCharge);
            }
        }
        foreach ($details['charges'] as $newCharge) {
            $found = false;
            foreach ($dbCharges as $dbCharge) {
                if (stripos($dbCharge, $newCharge) !== false || 
                    stripos($newCharge, $dbCharge) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found && !empty(trim($newCharge))) {
                $allCharges[] = htmlspecialchars($newCharge);
            }
        }
        $details['charges'] = $allCharges;

        try {
            $db->beginTransaction();

            $sel = $db->prepare("SELECT id FROM inmates WHERE inmate_id = ? LIMIT 1");
            $sel->execute([$dkt]);
            $existingId = $sel->fetchColumn();

            if ($existingId) {
                $upd = $db->prepare("
                    UPDATE inmates SET
                        name = COALESCE(NULLIF(?, ''), name),
                        age = COALESCE(NULLIF(?, ''), age),
                        sex = COALESCE(NULLIF(?, ''), sex),
                        race = COALESCE(NULLIF(?, ''), race),
                        height = COALESCE(NULLIF(?, ''), height),
                        weight = COALESCE(NULLIF(?, ''), weight),
                        hair_color = COALESCE(NULLIF(?, ''), hair_color),
                        eye_color = COALESCE(NULLIF(?, ''), eye_color),
                        booking_date = COALESCE(NULLIF(?, ''), booking_date),
                        release_date = COALESCE(NULLIF(?, ''), release_date),
                        bond_amount = COALESCE(NULLIF(?, ''), bond_amount),
                        arresting_agency = COALESCE(NULLIF(?, ''), arresting_agency),
                        le_number = COALESCE(NULLIF(?, ''), le_number),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $upd->execute([
                    $details['name'] ?? '',
                    $details['age'] ?? '',
                    $details['sex'] ?? '',
                    $details['race'] ?? '',
                    $details['height'] ?? '',
                    $details['weight'] ?? '',
                    $details['hair_color'] ?? '',
                    $details['eye_color'] ?? '',
                    $details['booking_date'] ?? '',
                    $details['release_date'] ?? '',
                    $details['bond_amount'] ?? '',
                    $details['arresting_agency'] ?? '',
                    $details['le_number'] ?? '',
                    $existingId
                ]);
            } else {
                if (!isValidInmateId($dkt)) {
                    throw new Exception('Invalid inmate ID');
                }

                $firstName = null;
                $lastName = null;
                if (!empty($details['name'])) {
                    $parts = preg_split('/\s+/', $details['name'], 2);
                    $firstName = $parts[0] ?? null;
                    $lastName = $parts[1] ?? null;
                }

                $ins = $db->prepare("
                    INSERT INTO inmates (
                        docket_number, inmate_id, name, first_name, last_name,
                        age, sex, race, height, weight, hair_color, eye_color,
                        booking_date, release_date, bond_amount, arresting_agency,
                        le_number, in_jail, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $ins->execute([
                    $dkt, $dkt, $details['name'] ?? null, $firstName, $lastName,
                    $details['age'] ?? null, $details['sex'] ?? null, $details['race'] ?? null,
                    $details['height'] ?? null, $details['weight'] ?? null,
                    $details['hair_color'] ?? null, $details['eye_color'] ?? null,
                    $details['booking_date'] ?? null, $details['release_date'] ?? null,
                    $details['bond_amount'] ?? null, $details['arresting_agency'] ?? null,
                    $details['le_number'] ?? null, 1
                ]);
            }

            $db->prepare("DELETE FROM charges WHERE inmate_id = ?")->execute([$dkt]);
            
            if (!empty($details['charges'])) {
                $insertCharge = $db->prepare("
                    INSERT INTO charges (inmate_id, charge_description, charge_type, created_at) 
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ");
                
                foreach ($details['charges'] as $chargeText) {
                    $chargeText = trim(strip_tags($chargeText));
                    if (!empty($chargeText)) {
                        $chargeType = null;
                        $uc = strtoupper($chargeText);
                        if (preg_match('/FELONY|AGGRAVATED|MURDER|ROBBERY|RAPE|KIDNAPPING|TRAFFICKING|ARMED|ASSAULT.*AGG|BURGLARY/i', $uc)) {
                            $chargeType = 'Felony';
                        } elseif (preg_match('/MISDEMEANOR|DUI|DRIVING UNDER|SIMPLE|BATTERY(?!\s+AGG)|THEFT|TRESPASS|DISORDERLY|VANDALISM|SHOPLIFTING|DOMESTIC/i', $uc)) {
                            $chargeType = 'Misdemeanor';
                        }
                        
                        $insertCharge->execute([$dkt, $chargeText, $chargeType]);
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Detail fetch DB error: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'details' => $details]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// Continue with your existing statistics and page rendering code...
// (I'll keep the rest of your code intact, just adding the navbar dropdown)

$search = $_GET['search'] ?? '';
$chargeFilter = $_GET['charge_type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

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

$whereConditions[] = "(i.name IS NOT NULL AND i.name != '' AND i.name NOT IN ('Inmate details', '*IN JAIL*', 'IN JAIL', 'UNKNOWN'))";
$whereConditions[] = "(i.inmate_id IS NOT NULL AND i.inmate_id != '' AND i.inmate_id NOT IN ('*IN JAIL*', 'IN JAIL'))";

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Statistics queries...
$totalInmates = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM inmates 
    WHERE name IS NOT NULL AND name != '' 
    AND name NOT IN ('Inmate details', '*IN JAIL*')
")->fetchColumn();

$activeInmates = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM inmates 
    WHERE in_jail = 1 
    AND name IS NOT NULL AND name != '' 
    AND name NOT IN ('Inmate details', '*IN JAIL*')
")->fetchColumn();

$releasedInmates = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM inmates 
    WHERE in_jail = 0 
    AND name IS NOT NULL AND name != '' 
    AND name NOT IN ('Inmate details', '*IN JAIL*')
")->fetchColumn();

$totalCharges = $db->query("SELECT COUNT(*) FROM charges")->fetchColumn();

$last24Hours = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM inmates 
    WHERE booking_date >= datetime('now', '-1 day')
    AND name IS NOT NULL AND name NOT IN ('Inmate details', '*IN JAIL*')
")->fetchColumn();

$violentCrimes = $db->query("
    SELECT COUNT(DISTINCT inmate_id) FROM charges
    WHERE charge_description LIKE '%MURDER%'
       OR charge_description LIKE '%ASSAULT AGGRAVATED%'
       OR charge_description LIKE '%RAPE%'
       OR charge_description LIKE '%ARMED ROBBERY%'
       OR charge_description LIKE '%KIDNAPPING%'
")->fetchColumn();

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

$topCharges = $db->query("
    SELECT charge_description, COUNT(*) as count
    FROM charges
    WHERE charge_description IS NOT NULL AND charge_description != ''
    GROUP BY charge_description
    ORDER BY count DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$maxChargeCount = !empty($topCharges) ? $topCharges[0]['count'] : 1;

$bookingTrends = $db->query("
    SELECT 
        DATE(booking_date) as date, 
        COUNT(DISTINCT inmate_id) as bookings,
        SUM(CASE WHEN in_jail = 0 THEN 1 ELSE 0 END) as releases
    FROM inmates
    WHERE booking_date >= date('now', '-30 days')
    AND name IS NOT NULL AND name NOT IN ('Inmate details', '*IN JAIL*')
    GROUP BY DATE(booking_date)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$trendDates = array_column($bookingTrends, 'date');
$trendBookings = array_column($bookingTrends, 'bookings');
$trendReleases = array_column($bookingTrends, 'releases');

// Pagination
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$countSql = "
    SELECT COUNT(DISTINCT i.inmate_id) as total
    FROM inmates i
    LEFT JOIN charges c ON i.inmate_id = c.inmate_id
    $whereClause
";
$countStmt = $db->prepare($countSql);
if (!empty($params)) {
    foreach ($params as $k => $p) {
        $countStmt->bindValue($k + 1, $p);
    }
}
$countStmt->execute();
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$sql = "
    SELECT i.*, 
           GROUP_CONCAT(c.charge_description, '|||') as all_charges,
           COUNT(DISTINCT c.id) as charge_count
    FROM inmates i
    LEFT JOIN charges c ON i.inmate_id = c.inmate_id
    $whereClause
    GROUP BY i.inmate_id
    ORDER BY i.updated_at DESC, i.booking_date DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);

$bindIndex = 1;
if (!empty($params)) {
    foreach ($params as $p) {
        $stmt->bindValue($bindIndex, $p);
        $bindIndex++;
    }
}
$stmt->bindValue($bindIndex, $perPage, PDO::PARAM_INT);
$bindIndex++;
$stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);

$stmt->execute();
$recentInmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

function buildPageUrl($pageNum) {
    $qs = $_GET;
    $qs['page'] = $pageNum;
    return '?' . http_build_query($qs);
}

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

        /* User Dropdown */
        .navbar-user {
            position: relative;
        }

        .user-dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: rgba(6, 182, 212, 0.1);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-dropdown-trigger:hover {
            background: rgba(6, 182, 212, 0.2);
            border-color: rgba(6, 182, 212, 0.5);
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--color-text);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--color-text-secondary);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 0.9rem;
        }

        /* Dropdown Menu */
        .dropdown-menu {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            min-width: 220px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 2000;
        }

        .navbar-user:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 1rem;
            border-bottom: 1px solid var(--color-border);
        }

        .dropdown-user-name {
            font-weight: 600;
            color: var(--color-text);
            margin-bottom: 0.25rem;
        }

        .dropdown-user-email {
            font-size: 0.8rem;
            color: var(--color-text-secondary);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--color-text-secondary);
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }

        .dropdown-item:hover {
            background: rgba(6, 182, 212, 0.1);
            color: var(--color-primary);
        }

        .dropdown-item i {
            width: 18px;
            text-align: center;
        }

        .dropdown-divider {
            height: 1px;
            background: var(--color-border);
            margin: 0.5rem 0;
        }

        .dropdown-item.logout {
            color: var(--color-danger);
        }

        .dropdown-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
        }

        /* Rest of your existing styles... */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

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
            color: var(--color-success);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--color-secondary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .navbar-menu {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .hero-stats {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span>Inmate360</span>
            </a>
            <ul class="navbar-menu">
                <li><a href="index.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="court_dashboard.php"><i class="fas fa-gavel"></i> Court Cases</a></li>
                <li><a href="#resources"><i class="fas fa-hands-helping"></i> Resources</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-dropdown-trigger">
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($userRole); ?></div>
                    </div>
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    </div>
                </div>
                
                <!-- Dropdown Menu -->
                <div class="dropdown-menu">
                    <div class="dropdown-header">
                        <div class="dropdown-user-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="dropdown-user-email"><?php echo htmlspecialchars($userEmail); ?></div>
                    </div>
                    
                    <a href="index.php" class="dropdown-item">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                    
                    <a href="court_dashboard.php" class="dropdown-item">
                        <i class="fas fa-gavel"></i>
                        <span>Court Cases</span>
                    </a>
                    
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>My Profile</span>
                    </a>
                    
                    <div class="dropdown-divider"></div>
                    
                    <a href="admin-dashboard.php" class="dropdown-item">
                        <i class="fas fa-cog"></i>
                        <span>Admin Dashboard</span>
                    </a>
                    
                    <a href="contact.php" class="dropdown-item">
                        <i class="fas fa-envelope"></i>
                        <span>Contact</span>
                    </a>
                    
                    <div class="dropdown-divider"></div>
                    
                    <a href="logout.php" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Important Community Notice -->
        <div class="alert">
            <i class="fas fa-info-circle" style="font-size: 1.5rem;"></i>
            <div>
                <strong>Community Safety Resource:</strong> This platform provides real-time jail data to help protect victims of abuse and assist law enforcement. Logged in as <strong><?php echo htmlspecialchars($userName); ?></strong>.
            </div>
        </div>

        <!-- Hero Stats -->
        <div class="hero-stats">
            <div class="stat-card primary">
                <div class="stat-header">
                    <div>
                        <div class="stat-title">Total Inmates</div>
                        <div class="stat-value"><?php echo number_format($totalInmates); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-arrow-up"></i>
                            <?php echo number_format($last24Hours); ?> in 24h
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
                            In custody
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
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
                            High priority
                        </div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
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

       
        
    </div>
<!-- Add this section after the Hero Stats section in index.php -->

<!-- Main Grid with Sidebar and Content -->
<div style="display: grid; grid-template-columns: 300px 1fr; gap: 2rem; margin-bottom: 2rem;">
    <!-- Sidebar -->
    <aside style="display: flex; flex-direction: column; gap: 1.5rem;">
        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem;">
            <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--color-text); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-search"></i>
                Search & Filter
            </h3>
            <form method="GET" action="">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Name, ID, or LE#..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="width: 100%; padding: 0.75rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text); font-size: 0.9rem; margin-bottom: 1rem;"
                >
                
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--color-text-secondary);">Status</label>
                    <select name="status" style="width: 100%; padding: 0.625rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-text); font-size: 0.875rem;">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="released" <?php echo $statusFilter === 'released' ? 'selected' : ''; ?>>Released</option>
                    </select>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--color-text-secondary);">Charge Type</label>
                    <select name="charge_type" style="width: 100%; padding: 0.625rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-text); font-size: 0.875rem;">
                        <option value="all" <?php echo $chargeFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="felony" <?php echo $chargeFilter === 'felony' ? 'selected' : ''; ?>>Felony</option>
                        <option value="misdemeanor" <?php echo $chargeFilter === 'misdemeanor' ? 'selected' : ''; ?>>Misdemeanor</option>
                    </select>
                </div>

                <button type="submit" style="width: 100%; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; border: none; cursor: pointer; background: var(--color-primary); color: white; display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
            </form>
        </div>

        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem;">
            <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--color-text); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-heart"></i>
                Victim Resources
            </h3>
            <ul style="list-style: none;">
                <li style="margin-bottom: 0.75rem;">
                    <a href="tel:911" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-phone-alt"></i> Emergency: 911
                    </a>
                </li>
                <li style="margin-bottom: 0.75rem;">
                    <a href="tel:18007997233" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-headset"></i> National DV Hotline
                    </a>
                </li>
                <li style="margin-bottom: 0.75rem;">
                    <a href="#" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-shield-alt"></i> Protective Orders
                    </a>
                </li>
                <li>
                    <a href="#" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-home"></i> Safe Housing
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main>
        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
            <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                    <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-line"></i>
                        Booking Trends
                    </h2>
                </div>
                <canvas id="bookingTrendsChart" style="max-height: 300px;"></canvas>
            </div>

            <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                    <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-pie"></i>
                        Charges
                    </h2>
                </div>
                <canvas id="chargeTypeChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Top Charges -->
        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-list-ol"></i>
                    Top 15 Charges
                </h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php 
                $colors = ['#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444',
                           '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1',
                           '#84cc16', '#eab308', '#22c55e', '#a855f7', '#fb923c'];
                foreach ($topCharges as $index => $crime): 
                    $progress = ($crime['count'] / $maxChargeCount) * 100;
                    $color = $colors[$index % count($colors)];
                ?>
                    <div style="display: grid; grid-template-columns: 2fr 3fr auto; gap: 1rem; align-items: center;">
                        <div style="font-size: 0.875rem; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($crime['charge_description']); ?>">
                            <?php echo htmlspecialchars(substr($crime['charge_description'], 0, 50)); ?>
                        </div>
                        <div style="background: var(--color-bg-tertiary); height: 32px; border-radius: 6px; overflow: hidden; position: relative;">
                            <div style="height: 100%; width: <?php echo $progress; ?>%; background: <?php echo $color; ?>; border-radius: 6px; display: flex; align-items: center; padding: 0 0.75rem; color: white; font-size: 0.75rem; font-weight: 600;">
                                <?php if ($progress > 20): ?>
                                    <?php echo $crime['count']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-weight: 700; color: var(--color-text); min-width: 40px; text-align: right;">
                            <?php echo $crime['count']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Inmates Table -->
        <div id="inmates" style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-users"></i>
                    Recent Inmates
                    <?php if (!empty($search) || $statusFilter !== 'all' || $chargeFilter !== 'all'): ?>
                        (Filtered)
                    <?php endif; ?>
                </h2>
                <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(16, 185, 129, 0.1); color: var(--color-success); border: 1px solid rgba(16, 185, 129, 0.3);">
                    <?php echo count($recentInmates); ?> Records
                </span>
            </div>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: var(--color-bg-tertiary);">
                        <tr>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">ID / LE#</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Name</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Age</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Booking Date</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Status</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Charges</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Bond</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentInmates)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--color-text-secondary); padding: 2rem;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                    <p>No valid inmate records found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentInmates as $inmate): 
                                // Skip invalid records
                                if (empty($inmate['name']) || in_array($inmate['name'], ['Inmate details', '*IN JAIL*', 'IN JAIL'])) {
                                    continue;
                                }
                                if (empty($inmate['inmate_id']) || in_array($inmate['inmate_id'], ['*IN JAIL*', 'IN JAIL'])) {
                                    continue;
                                }
                                
                                $charges_array = !empty($inmate['all_charges']) ? explode('|||', $inmate['all_charges']) : [];
                                $first_charge = '';
                                if (!empty($charges_array[0])) {
                                    $first_charge = trim($charges_array[0]);
                                }
                            ?>
                                <tr style="transition: background 0.2s;" onmouseover="this.style.background='var(--color-bg-tertiary)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <div style="font-family: monospace; font-size: 0.85rem;">
                                            <strong><?php echo htmlspecialchars($inmate['inmate_id']); ?></strong>
                                            <?php if (!empty($inmate['le_number'])): ?>
                                                <br><span style="color: var(--color-text-secondary);">LE: <?php echo htmlspecialchars($inmate['le_number']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <strong><?php echo htmlspecialchars($inmate['name'] ?? 'N/A'); ?></strong>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($inmate['age'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php if (!empty($inmate['booking_date'])): ?>
                                            <small><?php echo htmlspecialchars($inmate['booking_date']); ?></small>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-secondary);">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php if ($inmate['in_jail']): ?>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(16, 185, 129, 0.1); color: var(--color-success); border: 1px solid rgba(16, 185, 129, 0.3);">ACTIVE</span>
                                        <?php else: ?>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(59, 130, 246, 0.1); color: var(--color-secondary); border: 1px solid rgba(59, 130, 246, 0.3);">RELEASED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <div style="max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.85rem;" 
                                             title="<?php echo htmlspecialchars($first_charge ?: 'N/A'); ?>">
                                            <?php echo htmlspecialchars(substr($first_charge ?: 'N/A', 0, 40)); ?>
                                        </div>
                                        <?php if ($inmate['charge_count'] > 1): ?>
                                            <small style="color: var(--color-text-secondary); display: block; margin-top: 0.25rem;">
                                                +<?php echo $inmate['charge_count'] - 1; ?> more
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($inmate['bond_amount'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php
                                        $inmate['charges_array'] = $charges_array;
                                        $inmateJson = htmlspecialchars(json_encode($inmate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <button class="view-btn" data-inmate="<?php echo $inmateJson; ?>" style="padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; background: var(--color-primary); color: white; display: inline-flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
<!-- Add this section after the Hero Stats section in index.php -->

<!-- Main Grid with Sidebar and Content -->
<div style="display: grid; grid-template-columns: 300px 1fr; gap: 2rem; margin-bottom: 2rem;">
    <!-- Sidebar -->
    <aside style="display: flex; flex-direction: column; gap: 1.5rem;">
        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem;">
            <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--color-text); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-search"></i>
                Search & Filter
            </h3>
            <form method="GET" action="">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Name, ID, or LE#..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="width: 100%; padding: 0.75rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text); font-size: 0.9rem; margin-bottom: 1rem;"
                >
                
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--color-text-secondary);">Status</label>
                    <select name="status" style="width: 100%; padding: 0.625rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-text); font-size: 0.875rem;">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="released" <?php echo $statusFilter === 'released' ? 'selected' : ''; ?>>Released</option>
                    </select>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--color-text-secondary);">Charge Type</label>
                    <select name="charge_type" style="width: 100%; padding: 0.625rem; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 6px; color: var(--color-text); font-size: 0.875rem;">
                        <option value="all" <?php echo $chargeFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="felony" <?php echo $chargeFilter === 'felony' ? 'selected' : ''; ?>>Felony</option>
                        <option value="misdemeanor" <?php echo $chargeFilter === 'misdemeanor' ? 'selected' : ''; ?>>Misdemeanor</option>
                    </select>
                </div>

                <button type="submit" style="width: 100%; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; border: none; cursor: pointer; background: var(--color-primary); color: white; display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
            </form>
        </div>

        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem;">
            <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: var(--color-text); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-heart"></i>
                Victim Resources
            </h3>
            <ul style="list-style: none;">
                <li style="margin-bottom: 0.75rem;">
                    <a href="tel:911" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-phone-alt"></i> Emergency: 911
                    </a>
                </li>
                <li style="margin-bottom: 0.75rem;">
                    <a href="tel:18007997233" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-headset"></i> National DV Hotline
                    </a>
                </li>
                <li style="margin-bottom: 0.75rem;">
                    <a href="#" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-shield-alt"></i> Protective Orders
                    </a>
                </li>
                <li>
                    <a href="#" style="color: var(--color-text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; transition: color 0.2s;">
                        <i class="fas fa-home"></i> Safe Housing
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <!-- Main Content -->
    <main>
        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
            <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                    <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-line"></i>
                        Booking Trends
                    </h2>
                </div>
                <canvas id="bookingTrendsChart" style="max-height: 300px;"></canvas>
            </div>

            <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                    <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-pie"></i>
                        Charges
                    </h2>
                </div>
                <canvas id="chargeTypeChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Top Charges -->
        <div style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-list-ol"></i>
                    Top 15 Charges
                </h2>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php 
                $colors = ['#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444',
                           '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1',
                           '#84cc16', '#eab308', '#22c55e', '#a855f7', '#fb923c'];
                foreach ($topCharges as $index => $crime): 
                    $progress = ($crime['count'] / $maxChargeCount) * 100;
                    $color = $colors[$index % count($colors)];
                ?>
                    <div style="display: grid; grid-template-columns: 2fr 3fr auto; gap: 1rem; align-items: center;">
                        <div style="font-size: 0.875rem; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($crime['charge_description']); ?>">
                            <?php echo htmlspecialchars(substr($crime['charge_description'], 0, 50)); ?>
                        </div>
                        <div style="background: var(--color-bg-tertiary); height: 32px; border-radius: 6px; overflow: hidden; position: relative;">
                            <div style="height: 100%; width: <?php echo $progress; ?>%; background: <?php echo $color; ?>; border-radius: 6px; display: flex; align-items: center; padding: 0 0.75rem; color: white; font-size: 0.75rem; font-weight: 600;">
                                <?php if ($progress > 20): ?>
                                    <?php echo $crime['count']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-weight: 700; color: var(--color-text); min-width: 40px; text-align: right;">
                            <?php echo $crime['count']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Inmates Table -->
        <div id="inmates" style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
                <h2 style="font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-users"></i>
                    Recent Inmates
                    <?php if (!empty($search) || $statusFilter !== 'all' || $chargeFilter !== 'all'): ?>
                        (Filtered)
                    <?php endif; ?>
                </h2>
                <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(16, 185, 129, 0.1); color: var(--color-success); border: 1px solid rgba(16, 185, 129, 0.3);">
                    <?php echo count($recentInmates); ?> Records
                </span>
            </div>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: var(--color-bg-tertiary);">
                        <tr>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">ID / LE#</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Name</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Age</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Booking Date</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Status</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Charges</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Bond</th>
                            <th style="padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--color-border);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentInmates)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--color-text-secondary); padding: 2rem;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                    <p>No valid inmate records found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentInmates as $inmate): 
                                // Skip invalid records
                                if (empty($inmate['name']) || in_array($inmate['name'], ['Inmate details', '*IN JAIL*', 'IN JAIL'])) {
                                    continue;
                                }
                                if (empty($inmate['inmate_id']) || in_array($inmate['inmate_id'], ['*IN JAIL*', 'IN JAIL'])) {
                                    continue;
                                }
                                
                                $charges_array = !empty($inmate['all_charges']) ? explode('|||', $inmate['all_charges']) : [];
                                $first_charge = '';
                                if (!empty($charges_array[0])) {
                                    $first_charge = trim($charges_array[0]);
                                }
                            ?>
                                <tr style="transition: background 0.2s;" onmouseover="this.style.background='var(--color-bg-tertiary)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <div style="font-family: monospace; font-size: 0.85rem;">
                                            <strong><?php echo htmlspecialchars($inmate['inmate_id']); ?></strong>
                                            <?php if (!empty($inmate['le_number'])): ?>
                                                <br><span style="color: var(--color-text-secondary);">LE: <?php echo htmlspecialchars($inmate['le_number']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <strong><?php echo htmlspecialchars($inmate['name'] ?? 'N/A'); ?></strong>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($inmate['age'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php if (!empty($inmate['booking_date'])): ?>
                                            <small><?php echo htmlspecialchars($inmate['booking_date']); ?></small>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-secondary);">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php if ($inmate['in_jail']): ?>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(16, 185, 129, 0.1); color: var(--color-success); border: 1px solid rgba(16, 185, 129, 0.3);">ACTIVE</span>
                                        <?php else: ?>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: rgba(59, 130, 246, 0.1); color: var(--color-secondary); border: 1px solid rgba(59, 130, 246, 0.3);">RELEASED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <div style="max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.85rem;" 
                                             title="<?php echo htmlspecialchars($first_charge ?: 'N/A'); ?>">
                                            <?php echo htmlspecialchars(substr($first_charge ?: 'N/A', 0, 40)); ?>
                                        </div>
                                        <?php if ($inmate['charge_count'] > 1): ?>
                                            <small style="color: var(--color-text-secondary); display: block; margin-top: 0.25rem;">
                                                +<?php echo $inmate['charge_count'] - 1; ?> more
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($inmate['bond_amount'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-size: 0.9rem;">
                                        <?php
                                        $inmate['charges_array'] = $charges_array;
                                        $inmateJson = htmlspecialchars(json_encode($inmate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <button class="view-btn" data-inmate="<?php echo $inmateJson; ?>" style="padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; background: var(--color-primary); color: white; display: inline-flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div style="display: flex; gap: 0.5rem; align-items: center; justify-content: flex-end; margin-top: 1rem; flex-wrap: wrap;">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo buildPageUrl($page - 1); ?>" title="Previous Page" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600;">&laquo; Prev</a>
                    <?php else: ?>
                        <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600; opacity: 0.5; cursor: not-allowed;">&laquo; Prev</span>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1) {
                        echo '<a href="' . buildPageUrl(1) . '" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600;">1</a>';
                        if ($start > 2) echo '<span style="margin:0 4px; color: var(--color-text-secondary);">...</span>';
                    }
                    
                    for ($p = $start; $p <= $end; $p++): 
                        if ($p == $page):
                    ?>
                            <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-primary); background: linear-gradient(90deg, rgba(6,182,212,0.12), rgba(59,130,246,0.08)); border: 1px solid var(--color-primary); font-weight: 600;"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildPageUrl($p); ?>" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600;"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor;
                    
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<span style="margin:0 4px; color: var(--color-text-secondary);">...</span>';
                        echo '<a href="' . buildPageUrl($totalPages) . '" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600;">' . $totalPages . '</a>';
                    }
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo buildPageUrl($page + 1); ?>" title="Next Page" style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; text-decoration: none; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600;">Next &raquo;</a>
                    <?php else: ?>
                        <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 40px; padding: 0.5rem 0.75rem; border-radius: 8px; color: var(--color-text); background: var(--color-bg); border: 1px solid var(--color-border); font-weight: 600; opacity: 0.5; cursor: not-allowed;">Next &raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Modal Overlay -->
<div class="modal-overlay" id="modalOverlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 1rem;">
    <div class="modal" style="background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: 12px; max-width: 800px; width: 100%; padding: 1.5rem; color: var(--color-text); box-shadow: 0 10px 30px rgba(0,0,0,0.6); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
            <div>
                <div id="modalTitle" style="font-size: 1.125rem; font-weight: 700;">Inmate Details</div>
                <div style="font-size:0.85rem; color: var(--color-text-secondary);" id="modalSubTitle"></div>
            </div>
            <button id="modalClose" style="background: transparent; border: none; color: var(--color-text-secondary); font-size: 1.25rem; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Inmate ID</div>
                    <div id="m-inmate-id" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">LE Number</div>
                    <div id="m-le-number" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Age</div>
                    <div id="m-age" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Sex</div>
                    <div id="m-sex" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Race</div>
                    <div id="m-race" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Height</div>
                    <div id="m-height" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Weight</div>
                    <div id="m-weight" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
            </div>
            <div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Hair Color</div>
                    <div id="m-hair" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Eye Color</div>
                    <div id="m-eyes" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Booking Date</div>
                    <div id="m-booking-date" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Release Date</div>
                    <div id="m-release-date" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Status</div>
                    <div id="m-status" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Bond Amount</div>
                    <div id="m-bond" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
                <div style="margin-bottom: 0.75rem;">
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; text-transform: uppercase; font-weight: 600;">Arresting Agency</div>
                    <div id="m-agency" style="font-size: 0.95rem; font-weight: 600; color: var(--color-text);">N/A</div>
                </div>
            </div>
            <div style="grid-column: 1 / -1; background: var(--color-bg-tertiary); padding: 1rem; border-radius: 8px;">
                <div style="font-size: 0.85rem; color: var(--color-text-secondary); margin-bottom: 0.75rem; text-transform: uppercase; font-weight: 600;">Charges</div>
                <ul id="m-charges" style="list-style: none; max-height: 250px; overflow-y: auto;">
                    <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--color-border); font-size: 0.9rem; color: var(--color-text);">Loading charges...</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Chart.js initialization
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
        scales: {
            y: { beginAtZero: true, ticks: { color: '#9ca3af' }, grid: { color: 'rgba(55, 65, 81, 0.3)' } },
            x: { ticks: { color: '#9ca3af' }, grid: { color: 'rgba(55, 65, 81, 0.2)' } }
        },
        plugins: { legend: { labels: { color: '#e5e7eb' } } }
    }
});

const chargeCtx = document.getElementById('chargeTypeChart').getContext('2d');
new Chart(chargeCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($chargeBreakdown, 'type')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($chargeBreakdown, 'count')); ?>,
            backgroundColor: ['#3b82f6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
            borderColor: '#1a1f2e',
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'bottom', labels: { color: '#e5e7eb' } } }
    }
});

// Modal functionality
const modalOverlay = document.getElementById('modalOverlay');
const modalClose = document.getElementById('modalClose');

function openModal() {
    modalOverlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modalOverlay.style.display = 'none';
    document.body.style.overflow = 'auto';
}

modalClose.addEventListener('click', closeModal);
modalOverlay.addEventListener('click', function(e) {
    if (e.target === modalOverlay) closeModal();
});

function setModalField(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value ?? 'N/A';
}

document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const data = this.getAttribute('data-inmate');
        if (!data) return;

        let inmate;
        try {
            inmate = JSON.parse(data);
        } catch (err) {
            alert('Error loading inmate data');
            return;
        }

        if (!inmate.name || !inmate.inmate_id) {
            alert('Invalid inmate record');
            return;
        }

        document.getElementById('modalTitle').textContent = inmate.name;
        let subtitle = 'ID: ' + (inmate.inmate_id || 'N/A');
        if (inmate.le_number) subtitle += ' | LE#: ' + inmate.le_number;
        document.getElementById('modalSubTitle').textContent = subtitle;

        setModalField('m-inmate-id', inmate.inmate_id);
        setModalField('m-le-number', inmate.le_number || 'N/A');
        setModalField('m-age', inmate.age || 'N/A');
        setModalField('m-sex', inmate.sex || 'N/A');
        setModalField('m-race', inmate.race || 'N/A');
        setModalField('m-height', inmate.height || 'N/A');
        setModalField('m-weight', inmate.weight || 'N/A');
        setModalField('m-hair', inmate.hair_color || 'N/A');
        setModalField('m-eyes', inmate.eye_color || 'N/A');
        setModalField('m-booking-date', inmate.booking_date || 'N/A');
        setModalField('m-release-date', inmate.release_date || 'N/A');
        setModalField('m-status', inmate.in_jail ? 'ACTIVE - In Custody' : 'RELEASED');
        setModalField('m-bond', inmate.bond_amount || 'N/A');
        setModalField('m-agency', inmate.arresting_agency || 'N/A');

        const chargesList = document.getElementById('m-charges');
        chargesList.innerHTML = '';
        if (inmate.charges_array && inmate.charges_array.length > 0) {
            inmate.charges_array.forEach((charge, idx) => {
                const li = document.createElement('li');
                li.textContent = (idx + 1) + '. ' + charge;
                li.style.cssText = 'padding: 0.5rem 0; border-bottom: 1px solid var(--color-border); font-size: 0.9rem; color: var(--color-text);';
                chargesList.appendChild(li);
            });
        } else {
            const li = document.createElement('li');
            li.textContent = 'No charges on file';
            li.style.cssText = 'padding: 0.5rem 0; font-size: 0.9rem; color: var(--color-text);';
            chargesList.appendChild(li);
        }

        openModal();

        // Fetch fresh details
        const form = new FormData();
        form.append('action', 'fetch_inmate_details');
        form.append('dkt', inmate.inmate_id || '');
        form.append('le', inmate.le_number || '');

        fetch(window.location.pathname, {
            method: 'POST',
            body: form
        })
        .then(r => r.json())
        .then(json => {
            if (!json || !json.success) return;
            const details = json.details || {};
            
            setModalField('m-age', details.age || inmate.age || 'N/A');
            setModalField('m-sex', details.sex || inmate.sex || 'N/A');
            setModalField('m-race', details.race || inmate.race || 'N/A');
            setModalField('m-height', details.height || inmate.height || 'N/A');
            setModalField('m-weight', details.weight || inmate.weight || 'N/A');
            setModalField('m-hair', details.hair_color || inmate.hair_color || 'N/A');
            setModalField('m-eyes', details.eye_color || inmate.eye_color || 'N/A');
            setModalField('m-booking-date', details.booking_date || inmate.booking_date || 'N/A');
            setModalField('m-release-date', details.release_date || inmate.release_date || 'N/A');
            setModalField('m-bond', details.bond_amount || inmate.bond_amount || 'N/A');
            setModalField('m-agency', details.arresting_agency || inmate.arresting_agency || 'N/A');

            chargesList.innerHTML = '';
            if (Array.isArray(details.charges) && details.charges.length > 0) {
                details.charges.forEach((charge, idx) => {
                    const li = document.createElement('li');
                    li.textContent = (idx + 1) + '. ' + charge;
                    li.style.cssText = 'padding: 0.5rem 0; border-bottom: 1px solid var(--color-border); font-size: 0.9rem; color: var(--color-text);';
                    chargesList.appendChild(li);
                });
            }
        })
        .catch(err => console.error('Error fetching details:', err));
    });
});
</script>
            
    
</body>
</html>