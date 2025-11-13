<?php
/**
 * Inmate360 - User Login
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
$error = '';
$email = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if (empty($email) || empty($password)) {
        $error = 'Email and password required';
    } else {
        $result = $auth->login($email, $password, $rememberMe);
        
        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? 'index.php';
            $redirect = preg_replace('/[^a-zA-Z0-9_\-\.\/?&=]/', '', $redirect);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['message'];
            $email = htmlspecialchars($email);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #06b6d4;
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
        .navbar { background: var(--bg2); border-bottom: 1px solid var(--border); padding: 1rem 0; }
        .navbar-container { max-width: 1400px; margin: 0 auto; padding: 0 2rem; display: flex; justify-content: space-between; }
        .navbar-brand { display: flex; align-items: center; gap: 0.75rem; font-size: 1.5rem; font-weight: 700; color: var(--primary); text-decoration: none; }
        .navbar-brand i { font-size: 1.75rem; }
        .nav-links { display: flex; gap: 2rem; list-style: none; }
        .nav-links a { color: var(--text2); text-decoration: none; font-weight: 500; }
        .nav-links a:hover { color: var(--primary); }
        .container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .login-box { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; width: 100%; max-width: 420px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .login-header { text-align: center; margin-bottom: 2rem; }
        .login-header h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        .login-header p { color: var(--text2); font-size: 0.9rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; text-transform: uppercase; }
        .form-input { width: 100%; padding: 0.75rem; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); font-size: 0.95rem; }
        .form-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(6,182,212,0.1); }
        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; font-size: 0.85rem; }
        .form-checkbox { display: flex; align-items: center; gap: 0.5rem; }
        .form-checkbox input { accent-color: var(--primary); }
        .form-options a { color: var(--primary); text-decoration: none; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); }
        .btn { width: 100%; padding: 0.875rem; border-radius: 6px; font-weight: 600; border: none; cursor: pointer; text-transform: uppercase; background: linear-gradient(90deg, var(--primary), var(--secondary)); color: white; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(6,182,212,0.3); }
        .login-footer { text-align: center; margin-top: 1.5rem; color: var(--text2); font-size: 0.9rem; }
        .login-footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .footer { background: var(--bg2); border-top: 1px solid var(--border); padding: 2rem; text-align: center; color: var(--text2); font-size: 0.85rem; }
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
                <li><a href="register.php"><i class="fas fa-user-plus"></i> Register</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="login-box">
            <div class="login-header">
                <h1>Welcome Back</h1>
                <p>Sign in to <?php echo SITE_NAME; ?></p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input"
                           value="<?php echo $email; ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" id="password" required>
                </div>

                <div class="form-options">
                    <label class="form-checkbox">
                        <input type="checkbox" name="remember_me">
                        <span>Remember me</span>
                    </label>
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" class="btn">Sign In</button>
            </form>

            <div class="login-footer">
                No account? <a href="register.php">Register now</a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
    </footer>
</body>
</html>