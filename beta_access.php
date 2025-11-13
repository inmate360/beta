<?php
/**
 * Beta Access Login Page
 * Fixed to work with invite_gate.php and restore original gradient styling
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
require_once 'config.php';
require_once 'invite_gate.php';

// Don't redirect if already verified - this causes the loop!
// Just skip to the form or success page
// The index.php will handle the checkInviteAccess() call

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['invite_code'])) {
        $inviteCode = trim($_POST['invite_code']);
        
        if (empty($inviteCode)) {
            $error = 'Please enter an invite code';
        } else {
            // Use the validateInviteCode function from invite_gate.php
            if (validateInviteCode($inviteCode)) {
                // Set BOTH session variables
                $_SESSION['invite_verified'] = true;  // What invite_gate.php expects
                $_SESSION['beta_access'] = true;      // For backwards compatibility
                $_SESSION['invite_code'] = strtoupper($inviteCode);
                $_SESSION['access_granted_at'] = time();
                
                // Try to create/update beta user record
                try {
                    $db = new PDO('sqlite:' . DB_PATH);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Get the invite code ID
                    $stmt = $db->prepare("SELECT id FROM invite_codes WHERE code = ?");
                    $stmt->execute([strtoupper($inviteCode)]);
                    $inviteCodeId = $stmt->fetchColumn();
                    
                    if ($inviteCodeId) {
                        // Check if beta user already exists
                        $stmt = $db->prepare("SELECT id FROM beta_users WHERE invite_code_id = ?");
                        $stmt->execute([$inviteCodeId]);
                        $userId = $stmt->fetchColumn();
                        
                        if ($userId) {
                            // Update existing user's last access
                            $stmt = $db->prepare("
                                UPDATE beta_users 
                                SET last_access = CURRENT_TIMESTAMP,
                                    access_count = access_count + 1
                                WHERE id = ?
                            ");
                            $stmt->execute([$userId]);
                        } else {
                            // Create new beta user
                            $stmt = $db->prepare("
                                INSERT INTO beta_users (invite_code_id, first_access, last_access, access_count)
                                VALUES (?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
                            ");
                            $stmt->execute([$inviteCodeId]);
                        }
                    }
                    
                    // Log the activity
                    logUserActivity('beta_login', 'User logged in with invite code: ' . $inviteCode);
                    
                } catch (PDOException $e) {
                    // Log error but don't fail the login
                    error_log("Beta user tracking error: " . $e->getMessage());
                }
                
                // Show success message and provide link
                $success = 'Access granted! Redirecting to dashboard...';
                
                // Redirect after 1 second
                header("Refresh: 1; url=index.php");
            } else {
                $error = 'Invalid, expired, or already used invite code';
            }
        }
    }
}

// Get invite statistics for display
$stats = getInviteStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beta Access - Inmate360</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
            background: #0a0a0f;
        }

        /* Animated Gradient Background */
        .gradient-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            z-index: 0;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating Orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.6;
            animation: float 20s ease-in-out infinite;
        }

        .orb-1 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, #667eea, transparent);
            top: -10%;
            left: -10%;
            animation-delay: 0s;
        }

        .orb-2 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, #f093fb, transparent);
            bottom: -10%;
            right: -10%;
            animation-delay: 5s;
        }

        .orb-3 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, #4facfe, transparent);
            top: 30%;
            right: 20%;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }

        /* Dark Glassmorphism Container */
        .container {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            overflow-y: auto;
        }

        .glass-card {
            background: rgba(10, 10, 15, 0.7);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border-radius: 24px;
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 520px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            animation: slideUp 0.8s ease-out;
            margin: 2rem auto;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: white;
            margin-bottom: 1rem;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.5);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.5); }
            50% { transform: scale(1.05); box-shadow: 0 15px 40px rgba(102, 126, 234, 0.7); }
        }

        h1 {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 800;
            text-align: center;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .subtitle {
            color: rgba(255, 255, 255, 0.7);
            text-align: center;
            font-size: 0.95rem;
            font-weight: 400;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .badge {
            display: inline-block;
            background: rgba(102, 126, 234, 0.2);
            color: #a8b3ff;
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(102, 126, 234, 0.3);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        input[type="text"] {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            color: #ffffff;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        input[type="text"]::placeholder {
            color: rgba(255, 255, 255, 0.3);
            letter-spacing: 1px;
        }

        input[type="text"]:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(102, 126, 234, 0.6);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .btn-primary {
            width: 100%;
            padding: 1.1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.5);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: rgba(255, 255, 255, 0.4);
            margin: 2rem 0;
            font-size: 0.85rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .divider span {
            padding: 0 1rem;
        }

        .btn-secondary {
            width: 100%;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            color: rgba(255, 255, 255, 0.9);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .features {
            margin-top: 2.5rem;
            padding-top: 2.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .features-title {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .feature-card:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(102, 126, 234, 0.4);
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.3), rgba(118, 75, 162, 0.3));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 0.75rem;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .feature-name {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1.3;
            margin-bottom: 0.5rem;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.75rem;
            line-height: 1.5;
            font-weight: 400;
        }

        @media (max-width: 768px) {
            .glass-card {
                padding: 2rem 1.5rem;
            }

            h1 {
                font-size: 1.75rem;
            }

            .orb {
                filter: blur(60px);
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="gradient-bg"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="container">
        <div class="glass-card">
            <?php include 'nav_dropdown.php'; // Include the new dropdown menu ?>
            <div class="logo">
                <div class="logo-icon">I</div>
                <div class="badge">Closed Beta</div>
            </div>

            <h1>Inmate360</h1>
            <p class="subtitle">Real-Time Jail & Court Analytics Platform for Clayton County</p>
<center>
<a href="contact.php">Contact & About</a>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="invite_code">Enter Invite Code</label>
                    <input 
                        type="text" 
                        id="invite_code" 
                        name="invite_code" 
                        placeholder="XXXX-XXXX-XXXX"
                        required 
                        autocomplete="off"
                        autofocus
                    >
                </div>

                <button type="submit" class="btn-primary">
                    Access Platform
                </button>
            </form>

            <div class="divider">
                <span>Don't have access?</span>
            </div>

            <a href="invite_gate.php" class="btn-secondary">
                Request Beta Access
            </a>

            <div class="features">
                <div class="features-title">Platform Features</div>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">🔔</div>
                        <div class="feature-name">Victim Advocacy Notifications</div>
                        <div class="feature-description">Real-time alerts for victim advocates on case status changes and offender movements</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">⚠️</div>
                        <div class="feature-name">Offender Alerts</div>
                        <div class="feature-description">Automated notifications for releases, transfers, and custody status updates</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📍</div>
                        <div class="feature-name">Inmate Tracking</div>
                        <div class="feature-description">Comprehensive location history and real-time facility tracking with detailed analytics</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📋</div>
                        <div class="feature-name">Probation Case Management</div>
                        <div class="feature-description">Streamlined supervision workflows with compliance monitoring and violation tracking</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">🔍</div>
                        <div class="feature-name">Warrant Tracking</div>
                        <div class="feature-description">Active warrant database with automated status updates and multi-agency coordination</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">⚖️</div>
                        <div class="feature-name">Court Case Updates</div>
                        <div class="feature-description">Instant notifications on hearing schedules, verdicts, and court document filings</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
