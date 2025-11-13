<?php
/**
 * JailTrak - Court Case Detail View
 */

session_start();
require_once 'config.php';
require_once 'invite_gate.php';

checkInviteAccess();

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$caseId = $_GET['id'] ?? 0;

// Get case details
$stmt = $db->prepare("SELECT * FROM court_cases WHERE id = ?");
$stmt->execute([$caseId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    header('Location: court_dashboard.php');
    exit;
}

// Get charges
$stmt = $db->prepare("SELECT * FROM court_charges WHERE case_id = ? ORDER BY id");
$stmt->execute([$caseId]);
$charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get events
$stmt = $db->prepare("SELECT * FROM court_events WHERE case_id = ? ORDER BY event_date DESC");
$stmt->execute([$caseId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if linked to any inmates
$stmt = $db->prepare("
    SELECT i.*, icc.relationship 
    FROM inmate_court_cases icc
    JOIN inmates i ON icc.inmate_id = i.inmate_id
    WHERE icc.case_id = ?
");
$stmt->execute([$caseId]);
$linkedInmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Encode data for JavaScript
$caseJson = json_encode($case);
$chargesJson = json_encode($charges);
$eventsJson = json_encode($events);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case: <?= htmlspecialchars($case['case_number']) ?> - JailTrak</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a3e 100%);
            color: #e0e0e0;
            padding: 20px;
        }
        .container { max-width: 1600px; margin: 0 auto; }
        header {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(0,212,255,0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .case-header-info h1 {
            color: #00d4ff;
            font-size: 2em;
            margin-bottom: 10px;
        }
        .case-meta {
            color: #a0a0a0;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        .case-meta-item {
            background: rgba(0,212,255,0.1);
            padding: 10px;
            border-radius: 8px;
        }
        .case-meta-item strong {
            color: #00d4ff;
            display: block;
            margin-bottom: 5px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-primary { background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%); color: #0f0f23; }
        .btn-secondary { background: #2a2a4a; color: #e0e0e0; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,212,255,0.5); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .stat-card .icon { font-size: 2em; margin-bottom: 10px; }
        .stat-card .label { color: #a0a0a0; font-size: 0.9em; margin-bottom: 5px; }
        .stat-card .value { font-size: 2em; font-weight: bold; color: #00d4ff; }
        
        .tabs {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .tab-nav {
            display: flex;
            background: rgba(0,212,255,0.1);
            overflow-x: auto;
        }
        .tab-btn {
            padding: 15px 25px;
            background: transparent;
            border: none;
            color: #a0a0a0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .tab-btn:hover { background: rgba(0,212,255,0.2); color: #e0e0e0; }
        .tab-btn.active {
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
        }
        .tab-content {
            padding: 30px;
            display: none;
        }
        .tab-content.active { display: block; }
        
        .card {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .card h3 { color: #00d4ff; margin-bottom: 15px; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            padding: 12px;
            background: rgba(22,33,62,0.4);
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .info-label {
            font-weight: 600;
            color: #a0a0a0;
            font-size: 0.85em;
            text-transform: uppercase;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-size: 1.1em;
            color: #e0e0e0;
            font-weight: 500;
        }
        
        table { width: 100%; border-collapse: collapse; }
        th {
            background: rgba(0,212,255,0.2);
            padding: 12px;
            text-align: left;
            font-weight: 700;
            color: #00d4ff;
        }
        td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        tr:hover { background: rgba(0,212,255,0.05); }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .badge-active { background: rgba(255,170,0,0.3); color: #ffcc00; border: 1px solid #ffaa00; }
        .badge-disposed { background: rgba(0,255,136,0.3); color: #00ff88; border: 1px solid #00cc6a; }
        .badge-felony { background: rgba(255,68,68,0.3); color: #ff6868; border: 1px solid #ff4444; }
        .badge-misdemeanor { background: rgba(255,170,0,0.3); color: #ffcc00; border: 1px solid #ffaa00; }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(180deg, #00d4ff 0%, rgba(0,212,255,0.2) 100%);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
            padding: 15px;
            background: rgba(0,212,255,0.05);
            border-radius: 10px;
            border-left: 3px solid #00d4ff;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -33px;
            top: 20px;
            width: 12px;
            height: 12px;
            background: #00d4ff;
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(0,212,255,0.5);
        }
        .timeline-date {
            color: #00d4ff;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .timeline-event {
            color: #e0e0e0;
            line-height: 1.6;
        }
        
        @media (max-width: 1024px) {
            .case-meta { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'navbar.php'; ?>
        
        <header>
            <div class="case-header-info">
                <h1>⚖️ Case: <?= htmlspecialchars($case['case_number']) ?></h1>
                <div class="case-meta">
                    <div class="case-meta-item">
                        <strong>Defendant</strong>
                        <?= htmlspecialchars($case['defendant_name']) ?>
                    </div>
                    <div class="case-meta-item">
                        <strong>Judge</strong>
                        <?= htmlspecialchars($case['judge'] ?: 'Not assigned') ?>
                    </div>
                    <div class="case-meta-item">
                        <strong>Status</strong>
                        <span class="badge <?= stripos($case['case_status'], 'active') !== false ? 'badge-active' : 'badge-disposed' ?>">
                            <?= htmlspecialchars($case['case_status'] ?: 'Unknown') ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <a href="court_dashboard.php" class="btn btn-secondary">← Back</a>
                <a href="<?= 'https://weba.claytoncountyga.gov/casinqcgi-bin/wci201r.pgm?ctt=U&dvt=C&cyr=' . $case['case_year'] . '&ctp=CR&csq=' . $case['case_sequence'] ?>" 
                   class="btn btn-primary" target="_blank">🔗 View on Court Site</a>
            </div>
        </header>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">📋</div>
                <div class="label">Total Charges</div>
                <div class="value"><?= count($charges) ?></div>
            </div>
            <div class="stat-card">
                <div class="icon">📅</div>
                <div class="label">Court Events</div>
                <div class="value"><?= count($events) ?></div>
            </div>
            <div class="stat-card">
                <div class="icon">💰</div>
                <div class="label">Bond Amount</div>
                <div class="value" style="font-size: 1.5em;">
                    <?= $case['bond_amount'] ? '$' . number_format($case['bond_amount'], 2) : 'N/A' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon">👥</div>
                <div class="label">Linked Inmates</div>
                <div class="value"><?= count($linkedInmates) ?></div>
            </div>
        </div>
        
        <div class="tabs">
            <div class="tab-nav">
                <button class="tab-btn active" onclick="switchTab('overview')">📊 Overview</button>
                <button class="tab-btn" onclick="switchTab('charges')">⚖️ Charges</button>
                <button class="tab-btn" onclick="switchTab('events')">📅 Court Events</button>
                <button class="tab-btn" onclick="switchTab('inmates')">👥 Linked Inmates</button>
            </div>
            
            <!-- Overview Tab -->
            <div id="overview" class="tab-content active">
                <div class="card">
                    <h3>📋 Case Details</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Case Number</span>
                            <span class="info-value"><?= htmlspecialchars($case['case_number']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Defendant Name</span>
                            <span class="info-value"><?= htmlspecialchars($case['defendant_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Filing Date</span>
                            <span class="info-value"><?= $case['filing_date'] ? date('F j, Y', strtotime($case['filing_date'])) : 'N/A' ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Arrest Date</span>
                            <span class="info-value"><?= $case['arrest_date'] ? date('F j, Y', strtotime($case['arrest_date'])) : 'N/A' ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Case Status</span>
                            <span class="info-value">
                                <span class="badge <?= stripos($case['case_status'], 'active') !== false ? 'badge-active' : 'badge-disposed' ?>">
                                    <?= htmlspecialchars($case['case_status'] ?: 'Unknown') ?>
                                </span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Judge</span>
                            <span class="info-value"><?= htmlspecialchars($case['judge'] ?: 'Not assigned') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Attorney</span>
                            <span class="info-value"><?= htmlspecialchars($case['attorney'] ?: 'Not listed') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Bond Amount</span>
                            <span class="info-value" style="color: #00ff88;">
                                <?= $case['bond_amount'] ? '$' . number_format($case['bond_amount'], 2) : 'N/A' ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($case['offense']): ?>
                        <div style="margin-top: 20px;">
                            <strong style="color: #a0a0a0;">Primary Offense:</strong>
                            <div style="background: rgba(0,212,255,0.1); padding: 15px; border-radius: 8px; margin-top: 10px;">
                                <?= htmlspecialchars($case['offense']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($case['disposition']): ?>
                    <div class="card">
                        <h3>✅ Disposition</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Disposition</span>
                                <span class="info-value"><?= htmlspecialchars($case['disposition']) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Disposition Date</span>
                                <span class="info-value">
                                    <?= $case['disposition_date'] ? date('F j, Y', strtotime($case['disposition_date'])) : 'N/A' ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($case['sentence']): ?>
                            <div style="margin-top: 15px;">
                                <strong style="color: #a0a0a0;">Sentence:</strong>
                                <div style="background: rgba(0,212,255,0.1); padding: 15px; border-radius: 8px; margin-top: 10px;">
                                    <?= htmlspecialchars($case['sentence']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Charges Tab -->
            <div id="charges" class="tab-content">
                <div class="card">
                    <h3>⚖️ Charges (<?= count($charges) ?>)</h3>
                    <?php if (empty($charges)): ?>
                        <p style="color: #808080; text-align: center; padding: 20px;">No charges recorded</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Charge Description</th>
                                    <th>Type</th>
                                    <th>Code</th>
                                    <th>Plea</th>
                                    <th>Verdict</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($charges as $index => $charge): ?>
                                    <tr>
                                        <td><strong><?= $index + 1 ?></strong></td>
                                        <td><?= htmlspecialchars($charge['charge_description']) ?></td>
                                        <td>
                                            <?php if ($charge['charge_type']): ?>
                                                <span class="badge <?= stripos($charge['charge_type'], 'felony') !== false ? 'badge-felony' : 'badge-misdemeanor' ?>">
                                                    <?= htmlspecialchars($charge['charge_type']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #808080;">Unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($charge['charge_code'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($charge['plea'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($charge['verdict'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Events Tab -->
            <div id="events" class="tab-content">
                <div class="card">
                    <h3>📅 Court Events & Docket (<?= count($events) ?>)</h3>
                    <?php if (empty($events)): ?>
                        <p style="color: #808080; text-align: center; padding: 20px;">No court events recorded</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($events as $event): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date">
                                        <?= date('F j, Y', strtotime($event['event_date'])) ?>
                                        <?= $event['event_time'] ? ' at ' . date('g:i A', strtotime($event['event_time'])) : '' ?>
                                    </div>
                                    <div class="timeline-event">
                                        <?php if ($event['event_type']): ?>
                                            <strong style="color: #00d4ff;"><?= htmlspecialchars($event['event_type']) ?>:</strong>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($event['event_description']) ?>
                                        <?php if ($event['outcome']): ?>
                                            <br><em style="color: #a0a0a0;">Outcome: <?= htmlspecialchars($event['outcome']) ?></em>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Inmates Tab -->
            <div id="inmates" class="tab-content">
                <div class="card">
                    <h3>👥 Linked Inmates (<?= count($linkedInmates) ?>)</h3>
                    <?php if (empty($linkedInmates)): ?>
                        <p style="color: #808080; text-align: center; padding: 20px;">No inmates linked to this case</p>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="court_link_inmate.php?case_id=<?= $caseId ?>" class="btn btn-primary">
                                🔗 Link Inmate to Case
                            </a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Inmate ID</th>
                                    <th>Name</th>
                                    <th>Age</th>
                                    <th>Booking Date</th>
                                    <th>Status</th>
                                    <th>Relationship</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linkedInmates as $inmate): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($inmate['inmate_id']) ?></td>
                                        <td><strong><?= htmlspecialchars($inmate['name']) ?></strong></td>
                                        <td><?= htmlspecialchars($inmate['age']) ?></td>
                                        <td><?= date('m/d/Y', strtotime($inmate['booking_date'])) ?></td>
                                        <td>
                                            <?php if ($inmate['in_jail']): ?>
                                                <span style="color: #ff6868; font-weight: 600;">🔒 IN JAIL</span>
                                            <?php else: ?>
                                                <span style="color: #00ff88; font-weight: 600;">✓ Released</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($inmate['relationship']) ?></td>
                                        <td>
                                            <a href="index.php?search=<?= urlencode($inmate['name']) ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.85em;">
                                                View Inmate
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>