<?php
/**
 * Inmate360 - Court Case Dashboard
 * Unified court case analytics and tracking
 */

session_start();
require_once 'config.php';
require_once 'invite_gate.php';

checkInviteAccess();

// Initialize database connection
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Pagination settings
$perPage = 30;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $perPage;

// Get enhanced statistics including cross-linking
$stats = [
    'total' => $db->query("SELECT COUNT(*) FROM court_cases WHERE active = 1")->fetchColumn(),
    'active' => $db->query("SELECT COUNT(*) FROM court_cases WHERE case_status LIKE '%Active%' OR case_status LIKE '%Pending%' AND active = 1")->fetchColumn(),
    'disposed' => $db->query("SELECT COUNT(*) FROM court_cases WHERE disposition IS NOT NULL AND disposition != '' AND active = 1")->fetchColumn(),
    'judges' => $db->query("SELECT COUNT(DISTINCT judge) FROM court_cases WHERE judge IS NOT NULL AND judge != '' AND active = 1")->fetchColumn(),
    'total_charges' => $db->query("SELECT COUNT(*) FROM court_charges")->fetchColumn(),
    'last_update' => $db->query("SELECT MAX(scrape_time) FROM court_scrape_logs WHERE status = 'success'")->fetchColumn(),
    'total_bond' => $db->query("SELECT SUM(bond_amount) FROM court_cases WHERE bond_amount IS NOT NULL AND bond_amount > 0 AND active = 1")->fetchColumn(),
    'avg_bond' => $db->query("SELECT AVG(bond_amount) FROM court_cases WHERE bond_amount IS NOT NULL AND bond_amount > 0 AND active = 1")->fetchColumn(),
    'linked_inmates' => $db->query("SELECT COUNT(DISTINCT inmate_id) FROM inmate_court_cases")->fetchColumn(),
];

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$judgeFilter = $_GET['judge'] ?? '';
$bondMin = $_GET['bond_min'] ?? '';
$bondMax = $_GET['bond_max'] ?? '';
$filingFrom = $_GET['filing_from'] ?? '';
$filingTo = $_GET['filing_to'] ?? '';

// Build query
$whereConditions = ['active = 1'];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(c.defendant_name LIKE ? OR c.case_number LIKE ? OR c.offense LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($judgeFilter)) {
    $whereConditions[] = "c.judge = ?";
    $params[] = $judgeFilter;
}

if (!empty($bondMin) && is_numeric($bondMin)) {
    $whereConditions[] = "c.bond_amount >= ?";
    $params[] = $bondMin;
}

if (!empty($bondMax) && is_numeric($bondMax)) {
    $whereConditions[] = "c.bond_amount <= ?";
    $params[] = $bondMax;
}

if (!empty($filingFrom)) {
    $whereConditions[] = "c.filing_date >= ?";
    $params[] = $filingFrom;
}

if (!empty($filingTo)) {
    $whereConditions[] = "c.filing_date <= ?";
    $params[] = $filingTo;
}

if ($filter === 'active') {
    $whereConditions[] = "(c.case_status LIKE '%Active%' OR c.case_status LIKE '%Pending%')";
} elseif ($filter === 'disposed') {
    $whereConditions[] = "c.disposition IS NOT NULL AND c.disposition != ''";
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countQuery = "SELECT COUNT(*) FROM court_cases c WHERE $whereClause";
$stmt = $db->prepare($countQuery);
$stmt->execute($params);
$totalCases = $stmt->fetchColumn();
$totalPages = ceil($totalCases / $perPage);

// Get cases with charges and linking info
$query = "
    SELECT
        c.*,
        GROUP_CONCAT(DISTINCT ch.charge_description, '; ') as all_charges,
        COUNT(DISTINCT ch.id) as charge_count,
        CASE WHEN icc.id IS NOT NULL THEN 1 ELSE 0 END as has_linked_inmate,
        COUNT(DISTINCT icc.inmate_id) as linked_inmate_count
    FROM court_cases c
    LEFT JOIN court_charges ch ON c.id = ch.case_id
    LEFT JOIN inmate_court_cases icc ON c.id = icc.case_id
    WHERE $whereClause
    GROUP BY c.id
    ORDER BY c.filing_date DESC, c.case_number DESC
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate page range
$startRecord = $offset + 1;
$endRecord = min($offset + $perPage, $totalCases);

// Get judge statistics
$judgeStats = $db->query("
    SELECT judge, COUNT(*) as count
    FROM court_cases
    WHERE judge IS NOT NULL AND judge != '' AND active = 1
    GROUP BY judge
    ORDER BY count DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Get offense statistics
$offenseStats = $db->query("
    SELECT
        ch.charge_description,
        COUNT(*) as count
    FROM court_charges ch
    JOIN court_cases c ON ch.case_id = c.id
    WHERE c.active = 1
    GROUP BY ch.charge_description
    ORDER BY count DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Get case status breakdown
$statusStats = $db->query("
    SELECT case_status, COUNT(*) as count
    FROM court_cases
    WHERE case_status IS NOT NULL AND case_status != '' AND active = 1
    GROUP BY case_status
    ORDER BY count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Get bond range statistics
$bondStats = $db->query("
    SELECT
        CASE
            WHEN bond_amount < 1000 THEN 'Under $1,000'
            WHEN bond_amount < 5000 THEN '$1,000 - $4,999'
            WHEN bond_amount < 10000 THEN '$5,000 - $9,999'
            WHEN bond_amount < 25000 THEN '$10,000 - $24,999'
            ELSE '$25,000+'
        END as range,
        COUNT(*) as count
    FROM court_cases
    WHERE bond_amount IS NOT NULL AND bond_amount > 0 AND active = 1
    GROUP BY range
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get all judges for filter
$allJudges = $db->query("SELECT DISTINCT judge FROM court_cases WHERE judge IS NOT NULL AND judge != '' AND active = 1 ORDER BY judge")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Court Case Dashboard - Inmate360</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #e0e0e0;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }

        h1 {
            color: #00d4ff;
            font-size: 3em;
            text-shadow: 0 0 20px rgba(0,212,255,0.5);
        }

        .subtitle {
            color: #a0a0a0;
            font-size: 1.1em;
        }

        .view-switcher {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .view-btn {
            padding: 12px 25px;
            border: none;
            background: #2a2a4a;
            color: #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .view-btn:hover,
        .view-btn.active {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            border-color: #00d4ff;
            box-shadow: 0 0 15px rgba(0,212,255,0.5);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,212,255,0.3);
            border-color: rgba(0,212,255,0.5);
        }

        .stat-card h3 {
            font-size: 0.9em;
            color: #a0a0a0;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .stat-card .number {
            font-size: 2.5em;
            font-weight: bold;
            color: #00d4ff;
            text-shadow: 0 0 10px rgba(0,212,255,0.5);
        }

        .stat-card.bond .number {
            color: #00ff88;
        }

        .stat-card.link .number {
            color: #ff6b00;
        }

        .last-update {
            color: #808080;
            font-size: 0.9em;
            margin-top: 15px;
        }

        .controls {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            font-size: 1em;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: #16213e;
            color: #e0e0e0;
        }

        .search-box input::placeholder {
            color: #808080;
        }

        .search-box input:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 15px rgba(0,212,255,0.3);
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 20px;
            border: none;
            background: #2a2a4a;
            color: #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            border-color: #00d4ff;
            box-shadow: 0 0 15px rgba(0,212,255,0.5);
        }

        .advanced-filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 0.85em;
            color: #a0a0a0;
            font-weight: 600;
        }

        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: #16213e;
            color: #e0e0e0;
            font-size: 0.9em;
            min-width: 120px;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 10px rgba(0,212,255,0.3);
        }

        .refresh-btn {
            padding: 10px 25px;
            background: linear-gradient(135deg, #00ff88 0%, #00cc6a 100%);
            color: #0f0f23;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 0 10px rgba(0,255,136,0.3);
        }

        .refresh-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(0,255,136,0.5);
        }

        .pagination-info {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            color: #a0a0a0;
        }

        .pagination-info strong {
            color: #00d4ff;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2.5fr 1fr;
            gap: 30px;
        }

        .table-container {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            overflow-x: auto;
            overflow-y: visible;
            border: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            min-width: 1200px;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
        }

        th {
            padding: 15px 10px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85em;
            letter-spacing: 1px;
            white-space: nowrap;
        }

        td {
            padding: 15px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            vertical-align: top;
            color: #e0e0e0;
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: rgba(0,212,255,0.1);
        }

        .case-link {
            color: #00d4ff;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            transition: all 0.2s;
        }

        .case-link:hover {
            color: #00ff88;
            text-shadow: 0 0 10px rgba(0,255,136,0.5);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
            white-space: nowrap;
        }

        .badge-active {
            background: rgba(255,170,0,0.3);
            color: #ffcc00;
            border: 1px solid #ffaa00;
        }

        .badge-disposed {
            background: rgba(0,255,136,0.3);
            color: #00ff88;
            border: 1px solid #00cc6a;
        }

        .badge-linked {
            background: rgba(255,107,0,0.3);
            color: #ff9944;
            border: 1px solid #ff6b00;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .stats-section {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .stats-section h3 {
            color: #00d4ff;
            margin-bottom: 20px;
            font-size: 1.3em;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: rgba(22,33,62,0.6);
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 3px solid #00d4ff;
        }

        .stat-type {
            color: #e0e0e0;
            font-weight: 600;
            font-size: 0.9em;
        }

        .stat-count {
            background: #00d4ff;
            color: #0f0f23;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: bold;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 30px 20px;
        }

        .pagination a,
        .pagination span {
            padding: 10px 18px;
            background: #2a2a4a;
            color: #e0e0e0;
            border-radius: 8px;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.1);
            transition: all 0.3s;
            font-weight: 600;
        }

        .pagination a:hover {
            background: #3a3a5a;
            border-color: #00d4ff;
            box-shadow: 0 0 10px rgba(0,212,255,0.3);
            transform: translateY(-2px);
        }

        .pagination .current {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            border-color: #00d4ff;
            box-shadow: 0 0 15px rgba(0,212,255,0.5);
        }

        .pagination .disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .controls {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .advanced-filters {
                flex-direction: column;
                width: 100%;
            }

            .filter-group {
                width: 100%;
            }

            .filter-group select,
            .filter-group input {
                width: 100%;
                min-width: unset;
            }

            table {
                font-size: 0.75em;
            }

            th, td {
                padding: 8px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand">
                <span style="font-size: 2em;">🔗</span>
                <h1>Inmate360</h1>
                <span style="font-size: 2em;">🔗</span>
            </div>
            <p class="subtitle">Unified Jail & Court Analytics Platform</p>

            <div class="view-switcher">
                <a href="index.php" class="view-btn">
                    🏛️ Jail Inmates
                </a>
                <a href="court_dashboard.php" class="view-btn active">
                    ⚖️ Court Cases
                </a>
                <a href="admin-dashboard.php" class="view-btn">
                    🔧 Admin
                </a>
            </div>
        </header>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Cases</h3>
                <div class="number"><?= number_format($stats['total']) ?></div>
            </div>

            <div class="stat-card">
                <h3>Active Cases</h3>
                <div class="number"><?= number_format($stats['active']) ?></div>
            </div>

            <div class="stat-card">
                <h3>Disposed Cases</h3>
                <div class="number"><?= number_format($stats['disposed']) ?></div>
            </div>

            <div class="stat-card">
                <h3>Judges</h3>
                <div class="number"><?= number_format($stats['judges']) ?></div>
            </div>

            <div class="stat-card">
                <h3>Total Charges</h3>
                <div class="number"><?= number_format($stats['total_charges']) ?></div>
            </div>

            <div class="stat-card bond">
                <h3>Total Bond</h3>
                <div class="number">$<?= number_format($stats['total_bond']) ?></div>
            </div>

            <div class="stat-card bond">
                <h3>Avg Bond</h3>
                <div class="number">$<?= number_format($stats['avg_bond'], 0) ?></div>
            </div>

            <div class="stat-card link">
                <h3>Linked Inmates</h3>
                <div class="number"><?= number_format($stats['linked_inmates']) ?></div>
            </div>
        </div>

        <p class="last-update">
            Last Court Update: <?= $stats['last_update'] ? date('F j, Y g:i A', strtotime($stats['last_update'])) : 'Never' ?>
        </p>

        <div class="controls">
            <div class="search-box">
                <form method="GET" style="margin: 0;">
                    <input type="text" name="search" placeholder="🔍 Search by name, case number, or offense..." value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="hidden" name="judge" value="<?= htmlspecialchars($judgeFilter) ?>">
                    <input type="hidden" name="bond_min" value="<?= htmlspecialchars($bondMin) ?>">
                    <input type="hidden" name="bond_max" value="<?= htmlspecialchars($bondMax) ?>">
                    <input type="hidden" name="filing_from" value="<?= htmlspecialchars($filingFrom) ?>">
                    <input type="hidden" name="filing_to" value="<?= htmlspecialchars($filingTo) ?>">
                </form>
            </div>

            <div class="filter-tabs">
                <a href="?filter=all&search=<?= urlencode($search) ?>&judge=<?= urlencode($judgeFilter) ?>&bond_min=<?= urlencode($bondMin) ?>&bond_max=<?= urlencode($bondMax) ?>&filing_from=<?= urlencode($filingFrom) ?>&filing_to=<?= urlencode($filingTo) ?>" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All Cases</a>
                <a href="?filter=active&search=<?= urlencode($search) ?>&judge=<?= urlencode($judgeFilter) ?>&bond_min=<?= urlencode($bondMin) ?>&bond_max=<?= urlencode($bondMax) ?>&filing_from=<?= urlencode($filingFrom) ?>&filing_to=<?= urlencode($filingTo) ?>" class="filter-btn <?= $filter === 'active' ? 'active' : '' ?>">Active</a>
                <a href="?filter=disposed&search=<?= urlencode($search) ?>&judge=<?= urlencode($judgeFilter) ?>&bond_min=<?= urlencode($bondMin) ?>&bond_max=<?= urlencode($bondMax) ?>&filing_from=<?= urlencode($filingFrom) ?>&filing_to=<?= urlencode($filingTo) ?>" class="filter-btn <?= $filter === 'disposed' ? 'active' : '' ?>">Disposed</a>
            </div>

            <div class="advanced-filters">
                <div class="filter-group">
                    <label>Judge</label>
                    <select name="judge">
                        <option value="">All Judges</option>
                        <?php foreach ($allJudges as $judge): ?>
                            <option value="<?= htmlspecialchars($judge) ?>" <?= $judgeFilter === $judge ? 'selected' : '' ?>>
                                <?= htmlspecialchars($judge) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Bond Min ($)</label>
                    <input type="number" name="bond_min" placeholder="Min" value="<?= htmlspecialchars($bondMin) ?>" min="0">
                </div>

                <div class="filter-group">
                    <label>Bond Max ($)</label>
                    <input type="number" name="bond_max" placeholder="Max" value="<?= htmlspecialchars($bondMax) ?>" min="0">
                </div>

                <div class="filter-group">
                    <label>Filing From</label>
                    <input type="date" name="filing_from" value="<?= htmlspecialchars($filingFrom) ?>">
                </div>

                <div class="filter-group">
                    <label>Filing To</label>
                    <input type="date" name="filing_to" value="<?= htmlspecialchars($filingTo) ?>">
                </div>
            </div>

            <button class="refresh-btn" onclick="window.location.reload()">♻️ Refresh</button>
        </div>

        <?php if ($totalCases > 0): ?>
        <div class="pagination-info">
            Showing <strong><?= $startRecord ?></strong> to <strong><?= $endRecord ?></strong> of <strong><?= $totalCases ?></strong> cases
            (Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong>)
        </div>
        <?php endif; ?>

        <div class="content-grid">
            <div>
                <div class="table-container">
                    <?php if (count($cases) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 10%;">Case Number</th>
                                    <th style="width: 15%;">Defendant Name</th>
                                    <th style="width: 20%;">Offense</th>
                                    <th style="width: 10%;">Filing Date</th>
                                    <th style="width: 12%;">Status</th>
                                    <th style="width: 15%;">Judge</th>
                                    <th style="width: 8%;">Charges</th>
                                    <th style="width: 10%;">Bond</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases as $case): ?>
                                    <tr>
                                        <td>
                                            <a class="case-link" href="court_case_view.php?id=<?= $case['id'] ?>">
                                                <?= htmlspecialchars($case['case_number']) ?>
                                            </a>
                                            <?php if ($case['has_linked_inmate']): ?>
                                                <span class="badge badge-linked">🔗 Linked</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($case['defendant_name']) ?></strong></td>
                                        <td style="font-size: 0.9em;">
                                            <?= htmlspecialchars(substr($case['offense'] ?: 'Not specified', 0, 50)) ?>
                                            <?= strlen($case['offense'] ?: '') > 50 ? '...' : '' ?>
                                        </td>
                                        <td><?= $case['filing_date'] ? date('m/d/Y', strtotime($case['filing_date'])) : 'N/A' ?></td>
                                        <td>
                                            <?php if ($case['case_status']): ?>
                                                <span class="badge <?= stripos($case['case_status'], 'active') !== false || stripos($case['case_status'], 'pending') !== false ? 'badge-active' : 'badge-disposed' ?>">
                                                    <?= htmlspecialchars($case['case_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #808080;">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($case['judge'] ?: 'Not assigned') ?></td>
                                        <td style="text-align: center;">
                                            <strong style="color: #00d4ff;"><?= $case['charge_count'] ?></strong>
                                        </td>
                                        <td style="color: #00ff88; font-weight: bold;">
                                            <?= $case['bond_amount'] ? '$' . number_format($case['bond_amount'], 2) : 'N/A' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: #808080; text-align: center; padding: 60px 20px;">No cases found</p>
                    <?php endif; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = ['filter' => $filter, 'search' => $search, 'judge' => $judgeFilter, 'bond_min' => $bondMin, 'bond_max' => $bondMax, 'filing_from' => $filingFrom, 'filing_to' => $filingTo];

                    if ($currentPage > 1): ?>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => 1])) ?>">« First</a>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $currentPage - 1])) ?>">‹ Prev</a>
                    <?php else: ?>
                        <span class="disabled">« First</span>
                        <span class="disabled">‹ Prev</span>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);

                    for ($i = $startPage; $i <= $endPage; $i++):
                        if ($i == $currentPage): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif;
                    endfor;
                    ?>

                    <?php
                    if ($currentPage < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $currentPage + 1])) ?>">Next ›</a>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $totalPages])) ?>">Last »</a>
                    <?php else: ?>
                        <span class="disabled">Next ›</span>
                        <span class="disabled">Last »</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="sidebar">
                <div class="stats-section">
                    <h3>⚖️ Top Judges</h3>
                    <?php foreach ($judgeStats as $stat): ?>
                        <div class="stat-item">
                            <span class="stat-type"><?= htmlspecialchars($stat['judge']) ?></span>
                            <span class="stat-count"><?= $stat['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="stats-section">
                    <h3>📊 Top Offenses</h3>
                    <?php foreach (array_slice($offenseStats, 0, 10) as $stat): ?>
                        <div class="stat-item">
                            <span class="stat-type"><?= htmlspecialchars(substr($stat['charge_description'], 0, 30)) ?></span>
                            <span class="stat-count"><?= $stat['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="stats-section">
                    <h3>💰 Bond Ranges</h3>
                    <?php foreach ($bondStats as $stat): ?>
                        <div class="stat-item">
                            <span class="stat-type"><?= htmlspecialchars($stat['range']) ?></span>
                            <span class="stat-count"><?= $stat['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="stats-section">
                    <h3>📈 Case Status</h3>
                    <?php foreach ($statusStats as $stat): ?>
                        <div class="stat-item">
                            <span class="stat-type"><?= htmlspecialchars($stat['case_status']) ?></span>
                            <span class="stat-count"><?= $stat['count'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>