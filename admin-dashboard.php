<?php
session_start();

// Include config first
require_once __DIR__ . '/config.php';

// Admin authentication - use config constant or fallback
$adminPassword = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'admin123';

if (!isset($_SESSION['scraper_admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === $adminPassword) {
            $_SESSION['scraper_admin_logged_in'] = true;
            // Log admin access
            logSystemAccess('admin_login', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        } else {
            $error = 'Invalid admin password';
        }
    }

    if (!isset($_SESSION['scraper_admin_logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Login - Inmate360</title>
            <style>
                body {
                    font-family: Arial;
                    background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%);
                    color: #e0e0e0;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    margin: 0;
                }
                .login-box {
                    background: #2a2a4a;
                    padding: 40px;
                    border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
                    max-width: 400px;
                    width: 100%;
                    border: 1px solid rgba(0,212,255,0.3);
                }
                h2 { color: #00d4ff; margin-bottom: 20px; text-align: center; }
                input {
                    width: 100%;
                    padding: 12px;
                    margin: 10px 0;
                    border: 2px solid rgba(255,255,255,0.2);
                    border-radius: 8px;
                    background: #16213e;
                    color: #e0e0e0;
                    font-size: 1em;
                }
                button {
                    width: 100%;
                    padding: 12px;
                    background: #00d4ff;
                    color: #0f0f23;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                    font-size: 1em;
                }
                button:hover { background: #00b8e6; }
                .error {
                    background: rgba(255,68,68,0.2);
                    padding: 10px;
                    border-radius: 5px;
                    color: #ff6868;
                    margin-bottom: 15px;
                }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>🔐 Inmate360 Admin Access</h2>
                <?php if (isset($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="admin_password" placeholder="Admin Password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin-dashboard.php');
    exit;
}

// Handle scraper actions
$message = '';
$messageType = '';
$adhocResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'run_scraper') {
        // UPDATED: Use live_scraper.php instead of scraper.php
        $scriptPath = __DIR__ . '/live_scraper.php';

        // Run scraper in background
        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B php \"$scriptPath\" > NUL 2>&1";
        } else {
            $command = "php \"$scriptPath\" > /dev/null 2>&1 &";
        }

        $startTime = microtime(true);
        exec($command, $output, $returnCode);
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        if ($returnCode === 0) {
            $message = 'Live inmate scraper started successfully! Duration: ' . $duration . 's. Check logs for results.';
            $messageType = 'success';
            logSystemAccess('live_scraper_run', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        } else {
            $message = 'Live scraper started (background process). Check logs for details.';
            $messageType = 'success';
        }
    } elseif ($action === 'run_court_scraper') {
        $scriptPath = __DIR__ . '/court_scraper.php';
        $debugFlag = isset($_POST['debug_court_scraper']) ? ' --debug' : '';

        // Verify file exists
        if (!file_exists($scriptPath)) {
            $message = 'Court scraper file not found at: ' . $scriptPath;
            $messageType = 'error';
        } else {
            // Run court scraper in background with retry logic
            if (PHP_OS_FAMILY === 'Windows') {
                $command = "start /B php \"$scriptPath\"{$debugFlag} > NUL 2>&1";
            } else {
                $command = "php \"$scriptPath\"{$debugFlag} > /dev/null 2>&1 &";
            }

            $startTime = microtime(true);
            exec($command, $output, $returnCode);
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $message = '⚖️ Court scraper has been started in the background (Duration: ' . $duration . 's). ';
            $message .= 'The scraper will now query Clayton County court records, ';
            $message .= 'apply HTTP retry logic with exponential backoff, ';
            $message .= 'and store all cases in the database. Check "Recent Court Scrape Logs" below for results.';
            
            if ($debugFlag) {
                $message .= ' ✓ Debug mode is enabled - check court_debug.html for detailed output.';
            }
            $messageType = 'success';
            logSystemAccess('court_scraper_run', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        }

    } elseif ($action === 'run_case_details_scraper') {
        $scriptPath = __DIR__ . '/scrape_inmate_details.php';

        // Run case details scraper in background
        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B php \"$scriptPath\" --all > NUL 2>&1";
        } else {
            $command = "php \"$scriptPath\" --all > /dev/null 2>&1 &";
        }

        $startTime = microtime(true);
        exec($command, $output, $returnCode);
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $message = 'Inmate case details scraper has been started in the background. Check logs for results.';
        $messageType = 'success';
        logSystemAccess('case_details_scraper_run', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

    } elseif ($action === 'run_auto_link') {
        $scriptPath = __DIR__ . '/court_auto_link.php';

        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B php \"$scriptPath\" > NUL 2>&1";
        } else {
            $command = "php \"$scriptPath\" > /dev/null 2>&1 &";
        }

        exec($command, $output, $returnCode);

        $message = '🔗 Auto-linking process has been started in the background. This will match inmates to court cases and update the database.';
        $messageType = 'success';
        logSystemAccess('auto_link_run', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

    } elseif ($action === 'clear_logs') {
        $logFile = LOG_FILE;
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
            $message = 'Logs cleared successfully!';
            $messageType = 'success';
        }
    } elseif ($action === 'clear_cache') {
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->exec("DELETE FROM system_stats WHERE stat_date < date('now', '-30 days')");
            $db->exec("DELETE FROM court_stats WHERE stat_date < date('now', '-30 days')");
            $message = 'Old cache data cleared successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to clear cache: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'adhoc_court_search') {
        // Ad-hoc court search by last name (required) and first name (optional)
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');

        if (empty($lname)) {
            $message = 'Last name is required for the court search.';
            $messageType = 'error';
        } else {
            try {
                require_once __DIR__ . '/court_scraper.php';
                $scraper = new CourtScraper(false); // debug off
                $adhocResults = $scraper->searchByName($fname, $lname);

                if (empty($adhocResults)) {
                    $message = 'No court results returned for that name.';
                    $messageType = 'info';
                } else {
                    $message = 'Found ' . count($adhocResults) . ' results from court records.';
                    $messageType = 'success';
                    logSystemAccess('adhoc_court_search', $lname, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                }
            } catch (Exception $e) {
                $message = 'Court search failed: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'import_court_case') {
        // Import a single case record into court_cases
        $case_number = trim($_POST['case_number'] ?? '');
        $defendant_name = trim($_POST['defendant_name'] ?? '');
        $offense = trim($_POST['offense'] ?? '');
        $filing_date = trim($_POST['filing_date'] ?? '');
        $judge = trim($_POST['judge'] ?? '');
        $court = trim($_POST['court'] ?? '');

        if (empty($case_number) || empty($defendant_name)) {
            $message = 'Case number and defendant name are required to import a case.';
            $messageType = 'error';
        } else {
            try {
                $db = new PDO('sqlite:' . DB_PATH);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // Avoid duplicates
                $stmt = $db->prepare("SELECT id FROM court_cases WHERE case_number = ?");
                $stmt->execute([$case_number]);
                if ($stmt->fetch()) {
                    $message = 'Case already exists in the database.';
                    $messageType = 'info';
                } else {
                    $parts = preg_split('/[-\/]/', $case_number);
                    $year = $parts[0] ?? null;
                    $seq = $parts[1] ?? null;
                    $insert = $db->prepare("
                        INSERT INTO court_cases (case_number, case_year, case_sequence, defendant_name, offense, filing_date, judge, court, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $insert->execute([$case_number, $year, $seq, $defendant_name, $offense, $filing_date ?: null, $judge, $court]);
                    $message = 'Case imported successfully!';
                    $messageType = 'success';
                    logSystemAccess('import_court_case', $case_number, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
                }
            } catch (Exception $e) {
                $message = 'Failed to import case: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get database stats
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stats = [
        'total_inmates' => $db->query("SELECT COUNT(*) FROM inmates WHERE in_jail = 1")->fetchColumn(),
        'in_jail' => $db->query("SELECT COUNT(*) FROM inmates WHERE in_jail = 1")->fetchColumn(),
        'released' => $db->query("SELECT COUNT(*) FROM inmates WHERE in_jail = 0")->fetchColumn(),
        'total_charges' => $db->query("SELECT COUNT(*) FROM charges")->fetchColumn(),
        'last_scrape' => $db->query("SELECT MAX(scrape_time) FROM scrape_logs WHERE status = 'success'")->fetchColumn(),
        'total_scrapes' => $db->query("SELECT COUNT(*) FROM scrape_logs")->fetchColumn(),
        'successful_scrapes' => $db->query("SELECT COUNT(*) FROM scrape_logs WHERE status = 'success'")->fetchColumn(),
        'failed_scrapes' => $db->query("SELECT COUNT(*) FROM scrape_logs WHERE status = 'error'")->fetchColumn(),
        'total_court_cases' => $db->query("SELECT COUNT(*) FROM court_cases WHERE active = 1")->fetchColumn(),
        'court_charges' => $db->query("SELECT COUNT(*) FROM court_charges")->fetchColumn(),
        'last_court_scrape' => $db->query("SELECT MAX(scrape_time) FROM court_scrape_logs WHERE status = 'success'")->fetchColumn(),
        'court_scrapes' => $db->query("SELECT COUNT(*) FROM court_scrape_logs")->fetchColumn(),
        'successful_court_scrapes' => $db->query("SELECT COUNT(*) FROM court_scrape_logs WHERE status = 'success'")->fetchColumn(),
        'linked_cases' => $db->query("SELECT COUNT(DISTINCT case_number) FROM inmate_court_cases")->fetchColumn(),
        'linked_inmates' => $db->query("SELECT COUNT(DISTINCT inmate_id) FROM inmate_court_cases")->fetchColumn(),
        'total_bond' => $db->query("SELECT SUM(bond_amount) FROM court_cases WHERE bond_amount IS NOT NULL AND bond_amount > 0 AND active = 1")->fetchColumn(),
        'avg_bond' => $db->query("SELECT AVG(bond_amount) FROM court_cases WHERE bond_amount IS NOT NULL AND bond_amount > 0 AND active = 1")->fetchColumn(),
        'case_details_scraped' => $db->query("SELECT COUNT(*) FROM inmate_case_details")->fetchColumn(),
        'case_details_last_update' => $db->query("SELECT MAX(last_updated) FROM inmate_case_details")->fetchColumn(),
    ];

    // Get recent scrape logs
    $recentLogs = $db->query("
        SELECT * FROM scrape_logs
        ORDER BY scrape_time DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get recent court scrape logs
    $recentCourtLogs = $db->query("
        SELECT * FROM court_scrape_logs
        ORDER BY scrape_time DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $stats = null;
    $recentLogs = [];
    $recentCourtLogs = [];
}

// Get log file content (last 100 lines)
$logFile = LOG_FILE;
$logContent = '';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $logContent = implode('', array_slice($lines, -100));
}

// Helper function to log system access
function logSystemAccess($type, $email, $ip, $userAgent) {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $stmt = $db->prepare("
            INSERT INTO system_access_log (access_type, user_email, ip_address, user_agent)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$type, $email, $ip, $userAgent]);
    } catch (Exception $e) {
        // Silently fail if logging fails
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Inmate360</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%);
            color: #e0e0e0;
            padding: 20px;
            min-height: 100vh;
        }
        .container { max-width: 1600px; margin: 0 auto; }
        header {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 {
            color: #00d4ff;
            text-shadow: 0 0 20px rgba(0,212,255,0.5);
            font-size: 2em;
        }
        .logout-btn {
            background: #ff4444;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        .logout-btn:hover {
            background: #cc0000;
            box-shadow: 0 0 20px rgba(255,68,68,0.5);
        }
        .message {
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .message.success {
            background: rgba(0,255,136,0.2);
            border-left: 4px solid #00ff88;
            color: #00ff88;
        }
        .message.error {
            background: rgba(255,68,68,0.2);
            border-left: 4px solid #ff4444;
            color: #ff6868;
        }
        .message.info {
            background: rgba(100,150,255,0.2);
            border-left: 4px solid #6496ff;
            color: #6496ff;
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
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,212,255,0.3);
        }
        .stat-card .label {
            color: #a0a0a0;
            font-size: 0.9em;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .value {
            font-size: 2.5em;
            font-weight: bold;
            color: #00d4ff;
            text-shadow: 0 0 10px rgba(0,212,255,0.5);
        }
        .stat-card.jail .value { color: #4a9eff; }
        .stat-card.court .value { color: #00ff88; }
        .stat-card.link .value { color: #ff6b00; }
        .stat-card.case .value { color: #ffaa00; }
        .control-panel {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .control-panel h2 {
            color: #00d4ff;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .button-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1em;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            box-shadow: 0 0 15px rgba(0,212,255,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 25px rgba(0,212,255,0.5);
        }
        .btn-success {
            background: linear-gradient(135deg, #00ff88 0%, #00cc6a 100%);
            color: #0f0f23;
            box-shadow: 0 0 15px rgba(0,255,136,0.3);
        }
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 25px rgba(0,255,136,0.5);
        }
        .btn-warning {
            background: linear-gradient(135deg, #ffaa00 0%, #ff8800 100%);
            color: #0f0f23;
        }
        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 25px rgba(255,170,0,0.5);
        }
        .btn-danger {
            background: linear-gradient(135deg, #ff4444 0%, #cc0000 100%);
            color: white;
        }
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 25px rgba(255,68,68,0.5);
        }
        .card {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .card h2 {
            color: #00d4ff;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            padding: 15px;
            text-align: left;
            font-weight: 700;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            color: #e0e0e0;
        }
        tr:hover { background: rgba(0,212,255,0.05); }
        .status-success { color: #00ff88; font-weight: 600; }
        .status-error { color: #ff6868; font-weight: 600; }
        .log-viewer {
            background: #16213e;
            padding: 20px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            color: #00ff88;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-viewer::-webkit-scrollbar { width: 10px; }
        .log-viewer::-webkit-scrollbar-track { background: #0f0f23; border-radius: 5px; }
        .log-viewer::-webkit-scrollbar-thumb { background: #00d4ff; border-radius: 5px; }
        .refresh-notice {
            background: rgba(0,212,255,0.1);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #00d4ff;
            color: #00d4ff;
        }
        .debug-checkbox {
            margin-left: 15px;
            color: #a0a0a0;
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .button-group { grid-template-columns: 1fr; }
            header { flex-direction: column; gap: 20px; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔧 Inmate360 Admin Dashboard</h1>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </header>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($stats): ?>
        <div class="stats-grid">
            <div class="stat-card jail">
                <div class="label">Total Inmates</div>
                <div class="value"><?= number_format($stats['total_inmates']) ?></div>
            </div>
            <div class="stat-card jail">
                <div class="label">In Jail</div>
                <div class="value"><?= number_format($stats['in_jail']) ?></div>
            </div>
            <div class="stat-card jail">
                <div class="label">Released</div>
                <div class="value"><?= number_format($stats['released']) ?></div>
            </div>
            <div class="stat-card jail">
                <div class="label">Jail Charges</div>
                <div class="value"><?= number_format($stats['total_charges']) ?></div>
            </div>
            <div class="stat-card court">
                <div class="label">Court Cases</div>
                <div class="value"><?= number_format($stats['total_court_cases']) ?></div>
            </div>
            <div class="stat-card court">
                <div class="label">Court Charges</div>
                <div class="value"><?= number_format($stats['court_charges']) ?></div>
            </div>
            <div class="stat-card link">
                <div class="label">Linked Cases</div>
                <div class="value"><?= number_format($stats['linked_cases']) ?></div>
            </div>
            <div class="stat-card link">
                <div class="label">Linked Inmates</div>
                <div class="value"><?= number_format($stats['linked_inmates']) ?></div>
            </div>
            <div class="stat-card case">
                <div class="label">Case Details Scraped</div>
                <div class="value"><?= number_format($stats['case_details_scraped']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Jail Scrapes</div>
                <div class="value"><?= number_format($stats['total_scrapes']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Court Scrapes</div>
                <div class="value"><?= number_format($stats['court_scrapes']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Last Jail Scrape</div>
                <div class="value" style="font-size: 1.2em;">
                    <?= $stats['last_scrape'] ? date('M j, g:i A', strtotime($stats['last_scrape'])) : 'Never' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="label">Last Court Scrape</div>
                <div class="value" style="font-size: 1.2em;">
                    <?= $stats['last_court_scrape'] ? date('M j, g:i A', strtotime($stats['last_court_scrape'])) : 'Never' ?>
                </div>
            </div>
            <div class="stat-card case">
                <div class="label">Last Case Details Update</div>
                <div class="value" style="font-size: 1.2em;">
                    <?= $stats['case_details_last_update'] ? date('M j, g:i A', strtotime($stats['case_details_last_update'])) : 'Never' ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="control-panel">
            <h2>⚡ Live Inmate Scraper Controls</h2>
            <div class="refresh-notice">
                ℹ️ Live scraper aggregates Active Inmates and 48-Hour Docket, deduplicates by LE# or Docket, and consolidates charges. Runs in background. Check logs for results.
            </div>
            <form method="POST" class="button-group">
                <button type="submit" name="action" value="run_scraper" class="btn btn-primary">
                    🚀 Run Live Scraper Now
                </button>
                <button type="submit" name="action" value="clear_logs" class="btn btn-danger" 
                        onclick="return confirm('Clear all logs?')">
                    🗑️ Clear Logs
                </button>
                <a href="index.php" class="btn btn-warning">
                    📊 View Dashboard
                </a>
            </form>
        </div>

        <div class="control-panel">
            <h2>⚖️ Court Scraper Controls</h2>
            <div class="refresh-notice">
                ℹ️ <strong>Court Scraper Features:</strong> HTTP retry logic with exponential backoff, automatic rate limit handling (HTTP 429), 
                graceful 5xx error recovery, pagination support, and comprehensive debug logging. 
                Scraper runs in background and stores all cases in database.
            </div>
            <form method="POST" class="button-group" style="align-items: center;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" name="action" value="run_court_scraper" class="btn btn-success">
                        ⚖️ Run Court Scraper Now
                    </button>
                    <label class="debug-checkbox">
                        <input type="checkbox" name="debug_court_scraper" value="1">
                        Enable Debug Mode
                    </label>
                </div>
                <button type="submit" name="action" value="run_auto_link" class="btn btn-warning">
                    🔗 Run Auto-Link Process
                </button>
                <a href="court_dashboard.php" class="btn btn-warning">
                    📋 View Court Dashboard
                </a>
            </form>
        </div>

        <!-- Ad-hoc Court Search Panel -->
        <div class="control-panel">
            <h2>🔎 Ad-hoc Court Search</h2>
            <div class="refresh-notice">
                Use this to query the court site by last name (required) and optional first name, then import individual cases.
            </div>
            <form method="POST" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="action" value="adhoc_court_search">
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; color:#a0a0a0; font-weight:600; margin-bottom:6px;">First Name (optional)</label>
                    <input name="fname" placeholder="First name" style="width:100%; padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.06); background:#111827; color:#e0e0e0;">
                </div>
                <div style="flex:1; min-width:200px;">
                    <label style="display:block; color:#a0a0a0; font-weight:600; margin-bottom:6px;">Last Name (required)</label>
                    <input name="lname" placeholder="Last name" required style="width:100%; padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.06); background:#111827; color:#e0e0e0;">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary" style="margin-top:22px;">Search Court</button>
                </div>
            </form>

            <?php if (!empty($adhocResults)): ?>
            <div style="margin-top:20px;">
                <div class="card">
                    <h2>Search Results (<?= count($adhocResults) ?>)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Case Number</th>
                                <th>Defendant</th>
                                <th>Offense</th>
                                <th>Filing Date</th>
                                <th>Judge</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adhocResults as $res): ?>
                                <tr>
                                    <td><?= htmlspecialchars($res['case_number'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($res['defendant_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($res['offense'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($res['filing_date'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($res['judge'] ?? '') ?></td>
                                    <td>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="action" value="import_court_case">
                                            <input type="hidden" name="case_number" value="<?= htmlspecialchars($res['case_number'] ?? '') ?>">
                                            <input type="hidden" name="defendant_name" value="<?= htmlspecialchars($res['defendant_name'] ?? '') ?>">
                                            <input type="hidden" name="offense" value="<?= htmlspecialchars($res['offense'] ?? '') ?>">
                                            <input type="hidden" name="filing_date" value="<?= htmlspecialchars($res['filing_date'] ?? '') ?>">
                                            <input type="hidden" name="judge" value="<?= htmlspecialchars($res['judge'] ?? '') ?>">
                                            <input type="hidden" name="court" value="<?= htmlspecialchars($res['court'] ?? '') ?>">
                                            <button type="submit" class="btn btn-success">Import</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="control-panel">
            <h2>👤 Inmate Case Details Scraper</h2>
            <div class="refresh-notice">
                ℹ️ Case details scraper runs in the background to fetch detailed information for each inmate. 
                This includes charges, court dates, bonds, disposition, and sentencing information. Check logs for progress.
            </div>
            <form method="POST" class="button-group">
                <button type="submit" name="action" value="run_case_details_scraper" class="btn btn-success">
                    📋 Scrape All Inmate Case Details
                </button>
                <a href="index.php?view=inmates" class="btn btn-warning">
                    👥 View Inmates
                </a>
            </form>
        </div>

        <div class="control-panel">
            <h2>🛠️ System Maintenance</h2>
            <form method="POST" class="button-group">
                <button type="submit" name="action" value="clear_cache" class="btn btn-danger" 
                        onclick="return confirm('Clear old cache data (older than 30 days)?')">
                    🗑️ Clear Old Cache
                </button>
            </form>
        </div>

        <div class="card">
            <h2>📋 Recent Jail Scrape Logs (Last 20)</h2>
            <?php if (!empty($recentLogs)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Inmates Found</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?= date('M j, Y g:i A', strtotime($log['scrape_time'])) ?></td>
                        <td class="status-<?= $log['status'] ?>"><?= strtoupper($log['status']) ?></td>
                        <td><?= $log['inmates_found'] ?></td>
                        <td><?= htmlspecialchars($log['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="text-align: center; color: #808080; padding: 20px;">No jail scrape logs found</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>⚖️ Recent Court Scrape Logs (Last 20)</h2>
            <?php if (!empty($recentCourtLogs)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Cases Found</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCourtLogs as $log): ?>
                    <tr>
                        <td><?= date('M j, Y g:i A', strtotime($log['scrape_time'])) ?></td>
                        <td class="status-<?= $log['status'] ?>"><?= strtoupper($log['status']) ?></td>
                        <td><?= $log['cases_found'] ?></td>
                        <td><?= htmlspecialchars($log['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="text-align: center; color: #808080; padding: 20px;">No court scrape logs found</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>📄 Live Log Viewer (Last 100 Lines)</h2>
            <div class="log-viewer">
                <?= htmlspecialchars($logContent) ?: 'No logs available' ?>
            </div>
        </div>
    </div>
</body>
</html>