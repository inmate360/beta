<?php
session_start();

// Include config and utility functions
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/court_scraper.php'; // Will be used for ad-hoc search

// --- 1. Authentication and Logout ---

// Admin authentication - use config constant or fallback
$adminPassword = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'admin123';

if (!isset($_SESSION['scraper_admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === $adminPassword) {
            $_SESSION['scraper_admin_logged_in'] = true;
            // logSystemAccess('admin_login', null, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        } else {
            $error = 'Invalid admin password';
        }
    }

    if (!isset($_SESSION['scraper_admin_logged_in'])) {
        // Simplified login page HTML
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Login - Inmate360</title>
            <style>
                body { font-family: Arial; background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%); color: #e0e0e0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .login-box { background: #2a2a4a; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-width: 400px; width: 100%; border: 1px solid rgba(0,212,255,0.3); }
                h2 { color: #00d4ff; margin-bottom: 20px; text-align: center; }
                input { width: 100%; padding: 12px; margin: 10px 0; border: 2px solid rgba(255,255,255,0.2); border-radius: 8px; background: #16213e; color: #e0e0e0; font-size: 1em; }
                button { width: 100%; padding: 12px; background: #00d4ff; color: #0f0f23; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1em; }
                button:hover { background: #00b8e6; }
                .error { background: rgba(255,68,68,0.2); padding: 10px; border-radius: 5px; color: #ff6868; margin-bottom: 15px; }
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

// --- 2. Action Handling ---

$message = '';
$messageType = '';
$adhocResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'run_court_scraper') {
        $scriptPath = __DIR__ . '/court_scraper.php';
        $debugFlag = isset($_POST['debug_court_scraper']) ? ' --debug' : '';

        // Run court scraper in background
        if (PHP_OS_FAMILY === 'Windows') {
            $command = "start /B php \"$scriptPath\"{$debugFlag} > NUL 2>&1";
        } else {
            $command = "php \"$scriptPath\"{$debugFlag} > /dev/null 2>&1 &";
        }

        $startTime = microtime(true);
        exec($command, $output, $returnCode);
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $message = '⚖️ Court scraper has been started in the background (Duration: ' . $duration . 's). Check logs for results.';
        $messageType = 'success';

    } elseif ($action === 'adhoc_court_search') {
        // Ad-hoc court search by last name (required) and first name (optional)
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');

        if (empty($lname)) {
            $message = 'Last name is required for the court search.';
            $messageType = 'error';
        } else {
            try {
                // Use the CourtScraper class directly for ad-hoc search
                $scraper = new CourtScraper(false); // debug off
                $adhocResults = $scraper->searchByName($fname, $lname);

                if (empty($adhocResults)) {
                    $message = 'No court results returned for that name.';
                    $messageType = 'info';
                } else {
                    $message = 'Found ' . count($adhocResults) . ' results from court records.';
                    $messageType = 'success';
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
                
                // Check for duplicates
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
                }
            } catch (Exception $e) {
                $message = 'Failed to import case: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    // Removed: run_scraper, run_case_details_scraper, run_auto_link, clear_logs, clear_cache
}

// --- 3. Data Retrieval ---

// Get database stats
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stats = [
        'total_court_cases' => $db->query("SELECT COUNT(*) FROM court_cases WHERE active = 1")->fetchColumn(),
        'court_charges' => $db->query("SELECT COUNT(*) FROM court_charges")->fetchColumn(),
        'last_court_scrape' => $db->query("SELECT MAX(scrape_time) FROM court_scrape_logs WHERE status = 'success'")->fetchColumn(),
        'court_scrapes' => $db->query("SELECT COUNT(*) FROM court_scrape_logs")->fetchColumn(),
        'successful_court_scrapes' => $db->query("SELECT COUNT(*) FROM court_scrape_logs WHERE status = 'success'")->fetchColumn(),
    ];

    // Get recent court scrape logs
    $recentCourtLogs = $db->query("
        SELECT * FROM court_scrape_logs
        ORDER BY scrape_time DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $stats = null;
    $recentCourtLogs = [];
}

// --- 4. HTML Output ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Inmate360</title>
    <style>
        /* Simplified and focused CSS for the court-focused dashboard */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%); color: #e0e0e0; padding: 20px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        header { background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%); padding: 30px; border-radius: 15px; margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; }
        h1 { color: #00d4ff; text-shadow: 0 0 20px rgba(0,212,255,0.5); font-size: 2em; }
        .logout-btn { background: #ff4444; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s; }
        .logout-btn:hover { background: #cc0000; box-shadow: 0 0 20px rgba(255,68,68,0.5); }
        .message { padding: 15px 25px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; }
        .message.success { background: rgba(0,255,136,0.2); border-left: 4px solid #00ff88; color: #00ff88; }
        .message.error { background: rgba(255,68,68,0.2); border-left: 4px solid #ff4444; color: #ff6868; }
        .message.info { background: rgba(100,150,255,0.2); border-left: 4px solid #6496ff; color: #6496ff; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%); padding: 25px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .stat-card .label { color: #a0a0a0; font-size: 0.9em; margin-bottom: 10px; text-transform: uppercase; }
        .stat-card .value { font-size: 2.5em; font-weight: bold; color: #00d4ff; text-shadow: 0 0 10px rgba(0,212,255,0.5); }
        .control-panel { background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%); padding: 30px; border-radius: 15px; margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.1); }
        .control-panel h2 { color: #00d4ff; margin-bottom: 20px; font-size: 1.5em; }
        .button-group { display: flex; gap: 15px; align-items: center; }
        .btn { padding: 15px 30px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 1em; transition: all 0.3s; display: inline-flex; align-items: center; gap: 10px; }
        .btn-primary { background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%); color: #0f0f23; box-shadow: 0 0 15px rgba(0,212,255,0.3); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 5px 25px rgba(0,212,255,0.5); }
        .btn-success { background: linear-gradient(135deg, #00ff88 0%, #00cc6a 100%); color: #0f0f23; box-shadow: 0 0 15px rgba(0,255,136,0.3); }
        .btn-success:hover { transform: translateY(-3px); box-shadow: 0 5px 25px rgba(0,255,136,0.5); }
        .debug-checkbox { margin-left: 15px; color: #a0a0a0; }
        .card { background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%); padding: 30px; border-radius: 15px; margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th { background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%); color: #0f0f23; padding: 15px; text-align: left; font-weight: 700; }
        td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); color: #e0e0e0; }
        tr:hover { background: rgba(0,212,255,0.05); }
        .status-success { color: #00ff88; font-weight: 600; }
        .status-error { color: #ff6868; font-weight: 600; }
        .log-viewer { background: #16213e; padding: 20px; border-radius: 10px; font-family: 'Courier New', monospace; font-size: 0.9em; color: #00ff88; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .button-group { flex-direction: column; align-items: flex-start; } header { flex-direction: column; gap: 20px; text-align: center; } }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔧 Inmate360 Court Admin Dashboard</h1>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </header>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($stats): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Court Cases</div>
                <div class="value"><?= number_format($stats['total_court_cases']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Court Charges</div>
                <div class="value"><?= number_format($stats['court_charges']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Total Scrapes</div>
                <div class="value"><?= number_format($stats['court_scrapes']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Last Court Scrape</div>
                <div class="value" style="font-size: 1.2em;">
                    <?= $stats['last_court_scrape'] ? date('M j, g:i A', strtotime($stats['last_court_scrape'])) : 'Never' ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="control-panel">
            <h2>⚖️ Court Scraper Controls</h2>
            <form method="POST" class="button-group">
                <button type="submit" name="action" value="run_court_scraper" class="btn btn-success">
                    ⚖️ Run Court Scraper Now
                </button>
                <label class="debug-checkbox">
                    <input type="checkbox" name="debug_court_scraper" value="1">
                    Enable Debug Mode
                </label>
            </form>
        </div>

        <!-- Ad-hoc Court Search Panel -->
        <div class="control-panel">
            <h2>🔎 Ad-hoc Court Search</h2>
            <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="adhoc_court_search">
                <div style="flex:1; min-width:150px;">
                    <label style="display:block; color:#a0a0a0; font-weight:600; margin-bottom:6px;">First Name (optional)</label>
                    <input name="fname" placeholder="First name" style="width:100%; padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.06); background:#111827; color:#e0e0e0;">
                </div>
                <div style="flex:1; min-width:150px;">
                    <label style="display:block; color:#a0a0a0; font-weight:600; margin-bottom:6px;">Last Name (required)</label>
                    <input name="lname" placeholder="Last name" required style="width:100%; padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.06); background:#111827; color:#e0e0e0;">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Search Court</button>
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
                                    <td>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="action" value="import_court_case">
                                            <input type="hidden" name="case_number" value="<?= htmlspecialchars($res['case_number'] ?? '') ?>">
                                            <input type="hidden" name="defendant_name" value="<?= htmlspecialchars($res['defendant_name'] ?? '') ?>">
                                            <input type="hidden" name="offense" value="<?= htmlspecialchars($res['offense'] ?? '') ?>">
                                            <input type="hidden" name="filing_date" value="<?= htmlspecialchars($res['filing_date'] ?? '') ?>">
                                            <button type="submit" class="btn btn-success" style="padding: 5px 10px; font-size: 0.8em;">Import</button>
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

        <!-- Recent Court Scrape Logs -->
        <div class="card">
            <h2>Recent Court Scrape Logs</h2>
            <?php if (!empty($recentCourtLogs)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCourtLogs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['scrape_time']) ?></td>
                            <td class="status-<?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars(ucfirst($log['status'])) ?></td>
                            <td><?= htmlspecialchars($log['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>No court scrape logs found.</p>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>
