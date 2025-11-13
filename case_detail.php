<?php
/**
 * Court Case Detail Page
 */

$caseNumber = $_GET['case'] ?? '';

if (empty($caseNumber)) {
    header('Location: index_enhanced.php?view=cases');
    exit;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/jailtrak.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get case details
$stmt = $db->prepare("SELECT * FROM court_cases WHERE case_number = ?");
$stmt->execute([$caseNumber]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    die("Case not found");
}

// Get linked inmates
$stmt = $db->prepare("
    SELECT i.*, icc.defendant_name, icc.link_confidence, icc.link_method
    FROM inmates i
    INNER JOIN inmate_court_cases icc ON i.inmate_id = icc.inmate_id
    WHERE icc.case_number = ?
");
$stmt->execute([$caseNumber]);
$linkedInmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get case charges
$stmt = $db->prepare("SELECT * FROM court_case_charges WHERE case_number = ?");
$stmt->execute([$caseNumber]);
$caseCharges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get court events
$stmt = $db->prepare("SELECT * FROM court_events WHERE case_number = ? ORDER BY event_date DESC");
$stmt->execute([$caseNumber]);
$courtEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case Details - <?= htmlspecialchars($case['case_number']) ?></title>
    <link rel="stylesheet" href="theme-dark.css">
    <style>
        .detail-card {
            background: #1f2937;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #374151;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .detail-item {
            padding: 10px;
            background: #111827;
            border-radius: 5px;
        }

        .detail-label {
            font-size: 0.85em;
            color: #9ca3af;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1.1em;
            color: #fff;
            font-weight: 500;
        }

        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #6b7280;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            background: #4b5563;
        }

        .section-title {
            font-size: 1.3em;
            color: #60a5fa;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #374151;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #374151;
        }

        th {
            background: #111827;
            color: #9ca3af;
            font-weight: 600;
        }

        tr:hover {
            background: #1f2937;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index_enhanced.php?view=cases" class="back-btn">← Back to Cases</a>

        <header>
            <h1>⚖️ Court Case Details</h1>
            <h2><?= htmlspecialchars($case['case_number']) ?></h2>
        </header>

        <!-- Case Information -->
        <div class="detail-card">
            <div class="section-title">Case Information</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">Case Number</div>
                    <div class="detail-value"><?= htmlspecialchars($case['case_number']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Case Type</div>
                    <div class="detail-value"><?= htmlspecialchars($case['case_type'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Filing Date</div>
                    <div class="detail-value"><?= htmlspecialchars($case['filing_date'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><?= htmlspecialchars($case['case_status'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Judge</div>
                    <div class="detail-value"><?= htmlspecialchars($case['judge_name'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Prosecuting Attorney</div>
                    <div class="detail-value"><?= htmlspecialchars($case['prosecuting_attorney'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Defense Attorney</div>
                    <div class="detail-value"><?= htmlspecialchars($case['defense_attorney'] ?: 'N/A') ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Bond Amount</div>
                    <div class="detail-value"><?= htmlspecialchars($case['bond_amount'] ?: 'N/A') ?></div>
                </div>
            </div>
        </div>

        <!-- Linked Inmates -->
        <div class="detail-card">
            <div class="section-title">Linked Inmates (<?= count($linkedInmates) ?>)</div>
            <?php if (count($linkedInmates) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Inmate ID</th>
                        <th>Name (Jail Records)</th>
                        <th>Name (Court Records)</th>
                        <th>Age</th>
                        <th>Status</th>
                        <th>Link Confidence</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linkedInmates as $inmate): ?>
                    <tr>
                        <td><?= htmlspecialchars($inmate['inmate_id']) ?></td>
                        <td><?= htmlspecialchars($inmate['name']) ?></td>
                        <td><?= htmlspecialchars($inmate['defendant_name']) ?></td>
                        <td><?= htmlspecialchars($inmate['age']) ?></td>
                        <td>
                            <?php if ($inmate['in_jail']): ?>
                                <span style="color: #ef4444;">🔒 IN JAIL</span>
                            <?php else: ?>
                                <span style="color: #10b981;">✓ Released</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format(floatval($inmate['link_confidence']) * 100, 0) ?>%</td>
                        <td>
                            <a href="inmate_detail.php?id=<?= urlencode($inmate['inmate_id']) ?>" 
                               style="color: #60a5fa; text-decoration: none;">View Inmate →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: #9ca3af;">No linked inmates</p>
            <?php endif; ?>
        </div>

        <!-- Court Events -->
        <?php if (count($courtEvents) > 0): ?>
        <div class="detail-card">
            <div class="section-title">Court Events (<?= count($courtEvents) ?>)</div>
            <table>
                <thead>
                    <tr>
                        <th>Event Type</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Court Room</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courtEvents as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['event_type']) ?></td>
                        <td><?= htmlspecialchars($event['event_date']) ?></td>
                        <td><?= htmlspecialchars($event['event_time']) ?></td>
                        <td><?= htmlspecialchars($event['event_status']) ?></td>
                        <td><?= htmlspecialchars($event['court_room']) ?></td>
                        <td><?= htmlspecialchars($event['notes']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
