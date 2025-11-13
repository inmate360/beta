<?php
/**
 * Inmate360 - User Registration
 * SQLite Membership System Integration
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'lib/AuthHelper.php';

// Redirect if logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$auth = new AuthHelper();
$errors = [];
$formData = [];

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $betaCode = trim($_POST['beta_code'] ?? '');
    $agreeTerms = isset($_POST['agree_terms']);

    $formData = ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName];

    // Validate
    if (empty($email)) $errors[] = 'Email required';
    if (empty($firstName)) $errors[] = 'First name required';
    if (empty($lastName)) $errors[] = 'Last name required';
    if (empty($password)) $errors[] = 'Password required';
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match';
    if (!$agreeTerms) $errors[] = 'Must agree to terms';

    // Register
    if (empty($errors)) {
        $result = $auth->registerUser($email, $password, $firstName, $lastName, $betaCode ?: null);
        
        if ($result['success']) {
            $_SESSION['user_id'] = $result['user_id'];
            $_SESSION['email'] = strtolower($email);
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $_SESSION['tier'] = $result['tier'];
            $_SESSION['login_time'] = time();
            
            header('Location: index.php?welcome=1');
            exit;
        } else {
            $errors[] = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #06b6d4;
            --primary-dark: #0891b2;
            --secondary: #3b82f6;
            --danger: #ef4444;
            --bg: #0f1419;
            --bg2: #1a1f2e;
            --text: #e5e7eb;
            --text2: #9ca3af;
            --border: #374151;
        }
        html, body { height: 100%; font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }
        body { display: flex; flex-direction: column; }
        .navbar { background: var(--bg2); border-bottom: 1px solid var(--border); padding: 1rem 0; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .navbar-container { max-width: 1400px; margin: 0 auto; padding: 0 2rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar-brand { display: flex; align-items: center; gap: 0.75rem; font-size: 1.5rem; font-weight: 700; color: var(--primary); text-decoration: none; }
        .navbar-brand i { font-size: 1.75rem; }
        .nav-links { display: flex; gap: 2rem; list-style: none; }
        .nav-links a { color: var(--text2); text-decoration: none; font-weight: 500; transition: color 0.2s; }
        .nav-links a:hover { color: var(--primary); }
        .container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .register-box { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; width: 100%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .register-header { text-align: center; margin-bottom: 2rem; }
        .register-header h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        .register-header p { color: var(--text2); font-size: 0.9rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-input { width: 100%; padding: 0.75rem; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 0.95rem; }
        .form-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(6,182,212,0.1); }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); }
        .alert ul { list-style: none; margin-left: 1.5rem; }
        .alert li { margin-bottom: 0.25rem; }
        .btn { width: 100%; padding: 0.875rem; border-radius: 6px; font-weight: 600; font-size: 1rem; border: none; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; background: linear-gradient(90deg, var(--primary), var(--secondary)); color: white; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(6,182,212,0.3); }
        .form-checkbox { display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; }
        .form-checkbox input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }
        .form-checkbox a { color: var(--primary); text-decoration: none; }
        .form-checkbox a:hover { text-decoration: underline; }
        .register-footer { text-align: center; margin-top: 1.5rem; color: var(--text2); font-size: 0.9rem; }
        .register-footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .footer { background: var(--bg2); border-top: 1px solid var(--border); padding: 2rem; text-align: center; color: var(--text2); font-size: 0.85rem; }
        @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span><?php echo SITE_NAME; ?></span>
            </a>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="contact.php"><i class="fas fa-envelope"></i> Contact</a></li>
                <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="register-box">
            <div class="register-header">
                <h1>Create Account</h1>
                <p>Join <?php echo SITE_NAME; ?> Community</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert">
                    <i class="fas fa-exclamation-circle" style="flex-shrink: 0;"></i>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input"
                               value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input"
                               value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input"
                           value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Beta Code (Optional)</label>
                    <input type="text" name="beta_code" class="form-input"
                           placeholder="Leave blank for free access">
                </div>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="agree_terms" required>
                        <span>I agree to the <a href="#">Terms</a></span>
                    </label>
                </div>

                <button type="submit" class="btn">Create Account</button>
            </form>

            <div class="register-footer">
                Have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
    </footer>
</body>
</html>