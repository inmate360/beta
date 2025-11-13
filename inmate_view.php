<?php
require_once 'config.php';
require_once 'invite_gate.php';
require_once 'scrape_inmate_details.php';

checkInviteAccess();

$inmateId = $_GET['id'] ?? null;
$fetchDetails = isset($_GET['fetch_details']) ? true : false;

if (!$inmateId) {
    die('No inmate ID specified.');
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM inmates WHERE id = ?");
    $stmt->execute([$inmateId]);
    $inmate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inmate) {
        die('Inmate not found.');
    }

    // If fetch_details is requested, scrape and get case details
    $caseDetails = null;
    $detailsLoading = false;
    
    if ($fetchDetails) {
        try {
            $scraper = new InmateCaseDetailScraper();
            $scraper->scrapeInmateDetails($inmate['inmate_id'], $inmate['inmate_id']);
            $caseDetails = $scraper->getCaseDetails($inmate['inmate_id'], $inmate['inmate_id']);
        } catch (Exception $e) {
            $detailsLoading = false;
        }
    } else {
        // Try to get cached details
        try {
            $stmt = $db->prepare("
                SELECT * FROM inmate_case_details 
                WHERE inmate_id = ? 
                ORDER BY last_updated DESC 
                LIMIT 1
            ");
            $stmt->execute([$inmate['inmate_id']]);
            $caseDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $caseDetails = null;
        }
    }

    $chargesStmt = $db->prepare("SELECT * FROM charges WHERE inmate_id = ?");
    $chargesStmt->execute([$inmate['inmate_id']]);
    $charges = $chargesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inmate Details - <?= htmlspecialchars($inmate['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%);
            color: #e0e0e0; 
            padding: 20px; 
        }
        .container { 
            max-width: 1000px; 
            margin: auto; 
            background: #1a1a2e; 
            padding: 25px; 
            border-radius: 15px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); 
        }
        h1 { color: #00d4ff; margin-bottom: 20px; }
        h2 { color: #00d4ff; margin-top: 25px; margin-bottom: 15px; font-size: 1.3em; }
        .detail-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px;
            margin-bottom: 20px;
        }
        .detail-item { 
            background: #2a2a4a; 
            padding: 15px; 
            border-radius: 10px;
            border-left: 3px solid #00d4ff;
        }
        .detail-item strong { 
            display: block; 
            color: #a0a0a0; 
            font-size: 0.9em; 
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .detail-item span { 
            font-size: 1.1em;
            color: #fff;
        }
        .charges-section, .case-details-section { 
            margin-top: 20px; 
            background: #2a2a4a;
            padding: 20px;
            border-radius: 10px;
        }
        .charge-card, .detail-card { 
            background: #1a1a2e; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 10px;
            border-left: 3px solid #00ff88;
        }
        .charge-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            margin-left: 10px;
        }
        .charge-type.felony {
            background: rgba(255, 68, 68, 0.2);
            color: #ff6868;
        }
        .charge-type.misdemeanor {
            background: rgba(255, 170, 0, 0.2);
            color: #ffcc00;
        }
        .btn {
            display: inline-block;
            padding: 12px 25px;
            background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%);
            color: #0f0f23;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            margin-right: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,212,255,0.5);
        }
        .btn-secondary {
            background: #2a2a4a;
            color: #e0e0e0;
        }
        .btn-secondary:hover {
            background: #3a3a5a;
        }
        .back-link {
            color: #00d4ff;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .status-in-jail {
            background: rgba(255, 68, 68, 0.2);
            color: #ff6868;
        }
        .status-released {
            background: rgba(0, 255, 136, 0.2);
            color: #00ff88;
        }
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #00d4ff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .charge-list {
            display: grid;
            gap: 10px;
        }
        .court-date-item {
            background: #1a1a2e;
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid #ffcc00;
            margin-bottom: 8px;
        }
        .bond-item {
            background: #1a1a2e;
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid #00ff88;
            margin-bottom: 8px;
        }
        .no-details {
            padding: 20px;
            text-align: center;
            background: rgba(255, 170, 0, 0.1);
            border: 1px solid #ffaa00;
            border-radius: 8px;
            color: #ffcc00;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">&laquo; Back to Dashboard</a>

        <h1>📋 Inmate Details: <?= htmlspecialchars($inmate['name']) ?></h1>
        
        <span class="status-badge <?= $inmate['in_jail'] ? 'status-in-jail' : 'status-released' ?>">
            <?= $inmate['in_jail'] ? '🔒 IN JAIL' : '✓ RELEASED' ?>
        </span>

        <div class="detail-grid">
            <div class="detail-item">
                <strong>Docket #</strong>
                <span><?= htmlspecialchars($inmate['inmate_id']) ?></span>
            </div>
            <div class="detail-item">
                <strong>Age</strong>
                <span><?= htmlspecialchars($inmate['age']) ?></span>
            </div>
            <div class="detail-item">
                <strong>Sex</strong>
                <span><?= htmlspecialchars($inmate['sex'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <strong>Race</strong>
                <span><?= htmlspecialchars($inmate['race'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <strong>Height</strong>
                <span><?= htmlspecialchars($inmate['height'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <strong>Weight</strong>
                <span><?= htmlspecialchars($inmate['weight'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <strong>Arresting Agency</strong>
                <span><?= htmlspecialchars($inmate['arresting_agency'] ?? 'N/A') ?></span>
            </div>
            <div class="detail-item">
                <strong>Booking Date</strong>
                <span><?= htmlspecialchars($inmate['booking_date']) ?></span>
            </div>
            <div class="detail-item">
                <strong>Bond</strong>
                <span><?= htmlspecialchars($inmate['bond_amount']) ?></span>
            </div>
        </div>

        <div>
            <a href="?id=<?= htmlspecialchars($inmateId) ?>&fetch_details=1" class="btn">
                🔄 Fetch Case Details
            </a>
            <button onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
        </div>

        <div class="charges-section">
            <h2>📋 Jail Charges</h2>
            <?php if (!empty($charges)): ?>
                <div class="charge-list">
                    <?php foreach ($charges as $charge): ?>
                        <div class="charge-card">
                            <strong><?= htmlspecialchars($charge['charge_description']) ?></strong>
                            <span class="charge-type <?= strtolower($charge['charge_type'] ?? 'unknown') ?>">
                                <?= htmlspecialchars($charge['charge_type']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: #a0a0a0;">No jail charges listed.</p>
            <?php endif; ?>
        </div>

        <?php if ($caseDetails): ?>
            <div class="case-details-section">
                <h2>⚖️ Case Details</h2>
                
                <?php if (!empty($caseDetails['disposition'])): ?>
                    <div class="detail-card">
                        <strong>Disposition</strong>
                        <p><?= htmlspecialchars($caseDetails['disposition']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($caseDetails['sentence'])): ?>
                    <div class="detail-card">
                        <strong>Sentence</strong>
                        <p><?= htmlspecialchars($caseDetails['sentence']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($caseDetails['probation_status'])): ?>
                    <div class="detail-card">
                        <strong>Probation Status</strong>
                        <p><?= htmlspecialchars($caseDetails['probation_status']) ?></p>
                    </div>
                <?php endif; ?>

                <?php 
                $chargesDetail = json_decode($caseDetails['charges_json'] ?? '[]', true);
                if (!empty($chargesDetail)): 
                ?>
                    <h3 style="color: #00d4ff; margin-top: 15px; margin-bottom: 10px;">Detailed Charges</h3>
                    <div class="charge-list">
                        <?php foreach ($chargesDetail as $charge): ?>
                            <div class="charge-card">
                                <strong><?= htmlspecialchars($charge['description'] ?? 'N/A') ?></strong>
                                <span class="charge-type <?= strtolower($charge['type'] ?? 'unknown') ?>">
                                    <?= htmlspecialchars($charge['type'] ?? 'Unknown') ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php 
                $courtDates = json_decode($caseDetails['court_dates_json'] ?? '[]', true);
                if (!empty($courtDates)): 
                ?>
                    <h3 style="color: #00d4ff; margin-top: 15px; margin-bottom: 10px;">📅 Court Dates</h3>
                    <div class="charge-list">
                        <?php foreach ($courtDates as $date): ?>
                            <div class="court-date-item">
                                <strong><?= htmlspecialchars($date['date'] ?? $date['raw'] ?? 'N/A') ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php 
                $bonds = json_decode($caseDetails['bonds_json'] ?? '[]', true);
                if (!empty($bonds)): 
                ?>
                    <h3 style="color: #00d4ff; margin-top: 15px; margin-bottom: 10px;">💰 Bond Information</h3>
                    <div class="charge-list">
                        <?php foreach ($bonds as $bond): ?>
                            <div class="bond-item">
                                <strong>Amount:</strong> $<?= htmlspecialchars($bond['amount'] ?? 'N/A') ?><br>
                                <strong>Type:</strong> <?= htmlspecialchars($bond['type'] ?? 'N/A') ?><br>
                                <strong>Status:</strong> <?= htmlspecialchars($bond['status'] ?? 'N/A') ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p style="color: #808080; font-size: 0.9em; margin-top: 15px;">
                    Last updated: <?= htmlspecialchars($caseDetails['last_updated'] ?? 'Unknown') ?>
                </p>
            </div>
        <?php else: ?>
            <div class="case-details-section">
                <h2>⚖️ Case Details</h2>
                <div class="no-details">
                    📌 No case details fetched yet. Click "Fetch Case Details" to retrieve information.
                </div>
            </div>
        <?php endif; ?>

        <br>
        <a href="index.php" style="color: #00d4ff;">&laquo; Back to Dashboard</a>
    </div>
</body>
</html>