<?php
/**
 * JailTrak - Link Inmate to Court Case
 */

session_start();
require_once 'config.php';
require_once 'invite_gate.php';

checkInviteAccess();

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$caseId = $_GET['case_id'] ?? 0;
$message = '';
$error = '';

// Get case details
$stmt = $db->prepare("SELECT * FROM court_cases WHERE id = ?");
$stmt->execute([$caseId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    header('Location: court_dashboard.php');
    exit;
}

// Search for matching inmates
$matchingInmates = [];
if ($case['defendant_name']) {
    $nameParts = explode(' ', $case['defendant_name']);
    $lastName = end($nameParts);
    
    $stmt = $db->prepare("
        SELECT DISTINCT i.* 
        FROM inmates i
        WHERE i.name LIKE ?
        ORDER BY i.booking_date DESC
        LIMIT 20
    ");
    $stmt->execute(["%$lastName%"]);
    $matchingInmates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inmateId = $_POST['inmate_id'];
    $relationship = $_POST['relationship'];
    
    if (empty($inmateId)) {
        $error = 'Please select an inmate';
    } else {
        try {
            // Check if already linked
            $stmt = $db->prepare("
                SELECT id FROM inmate_court_cases 
                WHERE inmate_id = ? AND case_id = ?
            ");
            $stmt->execute([$inmateId, $caseId]);
            
            if ($stmt->fetch()) {
                $error = 'This inmate is already linked to this case';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO inmate_court_cases (inmate_id, case_id, relationship)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$inmateId, $caseId, $relationship]);
                
                header("Location: court_case_view.php?id=$caseId&tab=inmates&success=linked");
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error linking inmate: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Inmate to Case - JailTrak</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #1a1a3e 100%);
            color: #e0e0e0;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        header {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(0,212,255,0.3);
        }
        h1 { color: #00d4ff; text-shadow: 0 0 20px rgba(0,212,255,0.5); margin-bottom: 10px; }
        .back-link { display: inline-block; color: #00d4ff; text-decoration: none; margin-top: 10px; }
        .card {
            background: linear-gradient(135deg, #1e1e3f 0%, #2a2a4a 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #a0a0a0;
            font-weight: 600;
        }
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: #16213e;
            color: #e0e0e0;
            font-size: 1em;
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 10px rgba(0,212,255,0.3);
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1em;
            transition: all 0.3s;
        }
        .btn-primary { background: linear-gradient(135deg, #00d4ff 0%, #0099cc 100%); color: #0f0f23; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,212,255,0.5); }
        .btn-secondary { background: #2a2a4a; color: #e0e0e0; margin-left: 10px; }
        .error {
            background: rgba(255,68,68,0.2);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ff4444;
            color: #ff6868;
        }
        .info-box {
            background: rgba(0,212,255,0.1);
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #00d4ff;
            margin-bottom: 20px;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th {
            background: rgba(0,212,255,0.2);
            padding: 12px;
            text-align: left;
            font-weight: 700;
            color: #00d4ff;
        }
        td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        tr:hover { background: rgba(0,212,255,0.05); }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'navbar.php'; ?>
        
        <header>
            <h1>🔗 Link Inmate to Court Case</h1>
            <p style="color: #a0a0a0;">Case: <?= htmlspecialchars($case['case_number']) ?> - <?= htmlspecialchars($case['defendant_name']) ?></p>
            <a href="court_case_view.php?id=<?= $caseId ?>&tab=inmates" class="back-link">← Back to Case</a>
        </header>
        
        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="info-box">
                <strong>ℹ️ Instructions:</strong> Select an inmate from the list below to link to this court case. 
                Inmates with matching names are shown first.
            </div>
            
            <h2 style="color: #00d4ff; margin-bottom: 20px;">Matching Inmates (<?= count($matchingInmates) ?>)</h2>
            
            <?php if (empty($matchingInmates)): ?>
                <p style="color: #808080; text-align: center; padding: 20px;">No matching inmates found</p>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Select Inmate</label>
                        <select name="inmate_id" required>
                            <option value="">-- Select Inmate --</option>
                            <?php foreach ($matchingInmates as $inmate): ?>
                                <option value="<?= htmlspecialchars($inmate['inmate_id']) ?>">
                                    <?= htmlspecialchars($inmate['name']) ?> - 
                                    <?= htmlspecialchars($inmate['inmate_id']) ?> - 
                                    Booked: <?= date('m/d/Y', strtotime($inmate['booking_date'])) ?>
                                    <?= $inmate['in_jail'] ? ' (IN JAIL)' : ' (Released)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Relationship to Case</label>
                        <select name="relationship">
                            <option value="Defendant">Defendant</option>
                            <option value="Co-Defendant">Co-Defendant</option>
                            <option value="Witness">Witness</option>
                            <option value="Victim">Victim</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">🔗 Link Inmate to Case</button>
                        <a href="court_case_view.php?id=<?= $caseId ?>&tab=inmates" class="btn btn-secondary" style="text-decoration: none;">Cancel</a>
                    </div>
                </form>
                
                <h3 style="color: #00d4ff; margin: 30px 0 15px 0;">Preview Matching Inmates</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Inmate ID</th>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Booking Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matchingInmates as $inmate): ?>
                            <tr>
                                <td><?= htmlspecialchars($inmate['inmate_id']) ?></td>
                                <td><strong><?= htmlspecialchars($inmate['name']) ?></strong></td>
                                <td><?= htmlspecialchars($inmate['age']) ?></td>
                                <td><?= date('m/d/Y', strtotime($inmate['booking_date'])) ?></td>
                                <td>
                                    <?php if ($inmate['in_jail']): ?>
                                        <span style="color: #ff6868;">🔒 IN JAIL</span>
                                    <?php else: ?>
                                        <span style="color: #00ff88;">✓ Released</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>