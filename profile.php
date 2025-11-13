<?php
/**
 * Inmate360 - User Profile & Account Management
 * Complete user profile management system with security features
 * Uses SQLite via config.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'lib/AuthHelper.php';

// Require authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = getDB();
$auth = new AuthHelper();
$user = getCurrentUser();
$errors = [];
$success = [];
$activeTab = $_GET['tab'] ?? 'overview';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Validate CSRF token on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        die('CSRF token validation failed');
    }
}

// ===== HANDLE PROFILE UPDATE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($firstName)) {
        $errors[] = 'First name is required';
    } elseif (strlen($firstName) < 2 || strlen($firstName) > 100) {
        $errors[] = 'First name must be 2-100 characters';
    }

    if (empty($lastName)) {
        $errors[] = 'Last name is required';
    } elseif (strlen($lastName) < 2 || strlen($lastName) > 100) {
        $errors[] = 'Last name must be 2-100 characters';
    }

    if (!empty($phone) && !preg_match('/^[0-9\-\+\(\)\s\.]{10,}$/', $phone)) {
        $errors[] = 'Invalid phone number format';
    }

    if (empty($errors)) {
        $result = $auth->updateUserProfile($_SESSION['user_id'], $firstName, $lastName, $phone ?: null);
        if ($result['success']) {
            $success[] = $result['message'];
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $user = getCurrentUser();
        } else {
            $errors[] = $result['message'];
        }
    }

    $activeTab = 'overview';
}

// ===== HANDLE PASSWORD CHANGE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    }

    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match';
    }

    if (empty($errors)) {
        $result = $auth->changePassword($_SESSION['user_id'], $currentPassword, $newPassword);
        if ($result['success']) {
            $success[] = $result['message'];
        } else {
            $errors[] = $result['message'];
        }
    }

    $activeTab = 'security';
}

// ===== GET USER DATA =====
$userStmt = $db->prepare("
    SELECT u.*, m.tier, m.beta_code, m.beta_code_used_at, m.status as membership_status, m.created_at as membership_created_at
    FROM users u
    LEFT JOIN memberships m ON u.id = m.user_id
    WHERE u.id = ? LIMIT 1
");
$userStmt->execute([$_SESSION['user_id']]);
$userData = $userStmt->fetch();

// ===== GET LOGIN HISTORY =====
$loginStmt = $db->prepare("
    SELECT id, email, ip_address, success, failure_reason, created_at
    FROM login_audit
    WHERE user_id = ? OR (user_id IS NULL AND email = ?)
    ORDER BY created_at DESC
    LIMIT 20
");
$loginStmt->execute([$_SESSION['user_id'], $_SESSION['email']]);
$loginHistory = $loginStmt->fetchAll();

// ===== GET MEMBERSHIP INFO =====
$memStmt = $db->prepare("
    SELECT * FROM memberships WHERE user_id = ? LIMIT 1
");
$memStmt->execute([$_SESSION['user_id']]);
$membership = $memStmt->fetch();

// Calculate account age
$createdDate = new DateTime($userData['created_at']);
$today = new DateTime();
$interval = $createdDate->diff($today);
$accountAge = $interval->days . ' days';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #06b6d4;
            --primary-dark: #0891b2;
            --secondary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #0f1419;
            --bg2: #1a1f2e;
            --bg3: #242b3d;
            --text: #e5e7eb;
            --text2: #9ca3af;
            --border: #374151;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        body { display: flex; flex-direction: column; }

        /* Navigation */
        .navbar {
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
            padding: 1rem 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
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
            color: var(--primary);
            text-decoration: none;
        }

        .navbar-brand i { font-size: 1.75rem; }

        .navbar-menu {
            display: flex;
            gap: 2rem;
            list-style: none;
        }

        .navbar-menu a {
            color: var(--text2);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .navbar-menu a:hover { color: var(--primary); }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            text-align: right;
        }

        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-tier { font-size: 0.75rem; color: var(--text2); text-transform: uppercase; letter-spacing: 0.5px; }

        /* Main Container */
        .container {
            flex: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        .page-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .page-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: var(--text2);
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--danger);
        }

        .alert ul {
            list-style: none;
            margin-left: 1.5rem;
        }

        .alert li { margin-bottom: 0.25rem; }

        /* Tabs */
        .tabs-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .tabs-menu {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .tabs-menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text2);
            transition: all 0.2s;
            margin-bottom: 0.5rem;
        }

        .tabs-menu-item:last-child { margin-bottom: 0; }

        .tabs-menu-item:hover {
            color: var(--primary);
            background: rgba(6, 182, 212, 0.1);
        }

        .tabs-menu-item.active {
            background: rgba(6, 182, 212, 0.2);
            color: var(--primary);
            font-weight: 600;
        }

        .tabs-content {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2rem;
        }

        .tab-section {
            display: none;
        }

        .tab-section.active {
            display: block;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 0.95rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        .form-input:disabled {
            background: var(--bg3);
            color: var(--text2);
            cursor: not-allowed;
        }

        .form-help {
            font-size: 0.85rem;
            color: var(--text2);
            margin-top: 0.25rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(6, 182, 212, 0.3);
        }

        .btn-secondary {
            background: var(--bg3);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Info Cards */
        .info-card {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-card-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .info-card-row:last-child { margin-bottom: 0; }

        .info-label {
            font-size: 0.875rem;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
            margin-top: 0.25rem;
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-primary {
            background: rgba(6, 182, 212, 0.2);
            color: var(--primary);
            border: 1px solid rgba(6, 182, 212, 0.3);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
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
            background: var(--bg3);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }

        tr:hover { background: var(--bg3); }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.25rem;
        }

        .strength-bar {
            flex: 1;
            height: 6px;
            background: var(--border);
            border-radius: 2px;
        }

        .strength-bar.filled-weak { background: var(--danger); }
        .strength-bar.filled-fair { background: var(--warning); }
        .strength-bar.filled-good { background: var(--primary); }
        .strength-bar.filled-strong { background: var(--success); }

        .strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            font-weight: 500;
        }

        /* Footer */
        .footer {
            background: var(--bg2);
            border-top: 1px solid var(--border);
            padding: 2rem;
            text-align: center;
            color: var(--text2);
            font-size: 0.85rem;
            margin-top: auto;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
        }

        .modal-header {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-title { font-size: 1.25rem; font-weight: 700; }

        .modal-body { margin-bottom: 1.5rem; }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .tabs-container { grid-template-columns: 1fr; }
            .tabs-menu { position: static; }
            .form-row { grid-template-columns: 1fr; }
            .info-card-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span><?php echo SITE_NAME; ?></span>
            </a>
            <ul class="navbar-menu">
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                    <div class="user-tier"><?php echo ucfirst($user['tier']); ?></div>
                </div>
                <div style="font-size: 1.5rem; color: var(--primary);">
                    <i class="fas fa-user-circle"></i>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-user-cog"></i> My Account</h1>
            <p>Manage your profile, security, and account settings</p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($success)): ?>
            <?php foreach ($success as $msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs-container">
            <!-- Sidebar Menu -->
            <div class="tabs-menu">
                <a href="?tab=overview" class="tabs-menu-item <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Overview
                </a>
                <a href="?tab=profile" class="tabs-menu-item <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="?tab=security" class="tabs-menu-item <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                    <i class="fas fa-lock"></i> Security
                </a>
                <a href="?tab=activity" class="tabs-menu-item <?php echo $activeTab === 'activity' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Activity
                </a>
                <a href="?tab=membership" class="tabs-menu-item <?php echo $activeTab === 'membership' ? 'active' : ''; ?>">
                    <i class="fas fa-gem"></i> Membership
                </a>
                <hr style="margin: 1rem 0; border: none; border-top: 1px solid var(--border);">
                <a href="logout.php" class="tabs-menu-item" style="color: var(--danger);">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>

            <!-- Tab Content -->
            <div class="tabs-content">
                <!-- Overview Tab -->
                <div class="tab-section <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
                    <div class="section-title">
                        <i class="fas fa-user"></i> Account Overview
                    </div>

                    <div class="info-card">
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Email Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($userData['email']); ?></div>
                            </div>
                        </div>
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo htmlspecialchars($userData['phone'] ?? 'Not provided'); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Member Since</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($userData['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">
                        <i class="fas fa-gem"></i> Membership Information
                    </div>

                    <div class="info-card">
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Tier</div>
                                <div class="info-value">
                                    <span class="badge badge-primary"><?php echo ucfirst($membership['tier']); ?></span>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <span class="badge badge-success"><?php echo ucfirst($membership['status']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($membership['beta_code'])): ?>
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Beta Code Used</div>
                                <div class="info-value"><?php echo htmlspecialchars($membership['beta_code']); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Beta Code Date</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($membership['beta_code_used_at'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-title">
                        <i class="fas fa-shield-alt"></i> Account Status
                    </div>

                    <div class="info-card">
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Account Status</div>
                                <div class="info-value">
                                    <span class="badge badge-success"><?php echo ucfirst($userData['status']); ?></span>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Last Login</div>
                                <div class="info-value">
                                    <?php echo $userData['last_login_at'] ? date('F d, Y \a\t H:i', strtotime($userData['last_login_at'])) : 'Never'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="?tab=profile" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>

                <!-- Edit Profile Tab -->
                <div class="tab-section <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                    <div class="section-title">
                        <i class="fas fa-edit"></i> Edit Profile
                    </div>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-input"
                                       value="<?php echo htmlspecialchars($userData['first_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-input"
                                       value="<?php echo htmlspecialchars($userData['last_name']); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-input"
                                   value="<?php echo htmlspecialchars($userData['email']); ?>" disabled>
                            <div class="form-help">Email cannot be changed. Contact support if needed.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input"
                                   value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>"
                                   placeholder="Optional">
                            <div class="form-help">Enter a valid phone number (minimum 10 digits)</div>
                        </div>

                        <div style="display: flex; gap: 1rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="?tab=overview" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>

                <!-- Security Tab -->
                <div class="tab-section <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                    <div class="section-title">
                        <i class="fas fa-lock"></i> Change Password
                    </div>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" id="new_password" required>
                            <div class="form-help">
                                Password must be at least 8 characters and contain:
                                <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                                    <li>One uppercase letter (A-Z)</li>
                                    <li>One lowercase letter (a-z)</li>
                                    <li>One number (0-9)</li>
                                    <li>One special character (!@#$%^&*)</li>
                                </ul>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" required>
                        </div>

                        <div style="display: flex; gap: 1rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                            <a href="?tab=overview" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                    <div style="margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border);">
                        <div class="section-title">
                            <i class="fas fa-shield-alt"></i> Security Features
                        </div>

                        <div class="info-card">
                            <div style="margin-bottom: 1rem;">
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                    <i class="fas fa-check-circle" style="color: var(--success); font-size: 1.25rem;"></i>
                                    <div>
                                        <div style="font-weight: 600;">Secure Password</div>
                                        <div style="font-size: 0.875rem; color: var(--text2);">Your password is hashed with bcrypt</div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                    <i class="fas fa-check-circle" style="color: var(--success); font-size: 1.25rem;"></i>
                                    <div>
                                        <div style="font-weight: 600;">Login Tracking</div>
                                        <div style="font-size: 0.875rem; color: var(--text2);">All login attempts are logged</div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <i class="fas fa-check-circle" style="color: var(--success); font-size: 1.25rem;"></i>
                                    <div>
                                        <div style="font-weight: 600;">Account Protection</div>
                                        <div style="font-size: 0.875rem; color: var(--text2);">Account lockout after 5 failed attempts</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div class="tab-section <?php echo $activeTab === 'activity' ? 'active' : ''; ?>">
                    <div class="section-title">
                        <i class="fas fa-history"></i> Login History
                    </div>

                    <?php if (!empty($loginHistory)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Status</th>
                                        <th>IP Address</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loginHistory as $log): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                                            <td>
                                                <?php if ($log['success']): ?>
                                                    <span class="badge badge-success">Success</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Failed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code style="font-size: 0.85rem;"><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                                            <td>
                                                <?php if (!$log['success'] && !empty($log['failure_reason'])): ?>
                                                    <?php echo htmlspecialchars($log['failure_reason']); ?>
                                                <?php else: ?>
                                                    Login successful
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem; color: var(--text2);">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <p>No login history available</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Membership Tab -->
                <div class="tab-section <?php echo $activeTab === 'membership' ? 'active' : ''; ?>">
                    <div class="section-title">
                        <i class="fas fa-gem"></i> Membership Details
                    </div>

                    <div class="info-card">
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Current Tier</div>
                                <div class="info-value">
                                    <span class="badge badge-primary"><?php echo ucfirst($membership['tier']); ?></span>
                                </div>
                            </div>
                            <div>
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <span class="badge badge-success"><?php echo ucfirst($membership['status']); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="info-card-row">
                            <div>
                                <div class="info-label">Member Since</div>
                                <div class="info-value"><?php echo date('F d, Y', strtotime($membership['created_at'])); ?></div>
                            </div>
                            <div>
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('F d, Y H:i', strtotime($membership['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Tier Features
                    </div>

                    <div class="info-card">
                        <?php 
                        $tierFeatures = MEMBERSHIP_TIERS[$membership['tier']]['features'] ?? [];
                        $tierDesc = MEMBERSHIP_TIERS[$membership['tier']]['description'] ?? 'Unknown tier';
                        ?>
                        <p style="margin-bottom: 1rem; color: var(--text2);"><?php echo htmlspecialchars($tierDesc); ?></p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <?php 
                            $allFeatures = ['search', 'view_basic', 'view_detailed', 'export', 'alerts', 'batch_import', 'admin_tools'];
                            foreach ($allFeatures as $feature):
                                $hasFeature = in_array('all', $tierFeatures) || in_array($feature, $tierFeatures);
                            ?>
                                <div style="padding: 1rem; background: var(--bg3); border-radius: 6px; border: 1px solid var(--border);">
                                    <?php if ($hasFeature): ?>
                                        <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle" style="color: var(--text2); margin-right: 0.5rem;"></i>
                                    <?php endif; ?>
                                    <span><?php echo ucwords(str_replace('_', ' ', $feature)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($membership['tier'] === 'community'): ?>
                        <div style="margin-top: 2rem; padding: 1.5rem; background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 8px;">
                            <div style="display: flex; align-items: flex-start; gap: 1rem;">
                                <i class="fas fa-lightbulb" style="color: var(--primary); font-size: 1.25rem; margin-top: 0.25rem;"></i>
                                <div>
                                    <div style="font-weight: 600; margin-bottom: 0.5rem;">Upgrade Your Account</div>
                                    <p style="font-size: 0.9rem; color: var(--text2); margin-bottom: 1rem;">
                                        Upgrade to a higher tier to unlock additional features like detailed searches, data export, and alerts.
                                    </p>
                                    <a href="contact.php?type=partnership" class="btn btn-primary" style="font-size: 0.85rem;">
                                        <i class="fas fa-envelope"></i> Contact Us
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
        <p style="margin-top: 0.5rem; font-size: 0.8rem;">Your data is encrypted and secured with industry-standard security practices.</p>
    </footer>

    <script>
        // Tab switching
        document.querySelectorAll('.tabs-menu-item').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.getAttribute('href').startsWith('?tab=')) {
                    e.preventDefault();
                    const tab = new URLSearchParams(this.getAttribute('href')).get('tab');
                    window.location.href = '?tab=' + tab;
                }
            });
        });

        // Password visibility toggle
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            if (field.type === 'password') {
                field.type = 'text';
            } else {
                field.type = 'password';
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.3s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>