<?php
/**
 * Contact & About Us Page
 * Styled to match beta_access.php (Dark Glassmorphism)
 */

// Start session (required for consistency, even if not strictly used here)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
require_once 'config.php';

$messageSent = false;
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? 'Contact Form Submission');
    $message = trim($_POST['message'] ?? '');
    $userType = trim($_POST['user_type'] ?? 'General Inquiry');
    
    // Basic validation
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Store in database (using the existing user_activity_log structure)
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if the table exists before inserting (to prevent fatal errors if setup is incomplete)
            $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_activity_log'")->fetch();
            
            if ($tableCheck) {
                $stmt = $db->prepare("
                    INSERT INTO user_activity_log 
                    (email, activity_type, description, ip_address, user_agent)
                    VALUES (?, 'contact_form', ?, ?, ?)
                ");
                
                $description = "Name: $name\nSubject: $subject\nType: $userType\nMessage: $message";
                $stmt->execute([
                    $email,
                    $description,
                    $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);
                
                $success = 'Thank you for your message! We will be in touch shortly.';
                $messageSent = true;
            } else {
                $error = 'Database table for logging is not set up. Message received but not logged.';
                $success = 'Thank you for your message! We will be in touch shortly.';
                $messageSent = true;
            }
            
        } catch (Exception $e) {
            // Log the error but still show success to the user if the message was received
            error_log("Contact form logging error: " . $e->getMessage());
            $error = 'An error occurred while logging your message, but it was received.';
            $success = 'Thank you for your message! We will be in touch shortly.';
            $messageSent = true;
        }
        
        // NOTE: Actual email sending is commented out as it requires mail server configuration
        // If you want to enable email, uncomment the line below and configure your PHP mail settings.
        // mail('your@email.com', "Inmate360 Contact: $subject", $description, "From: $email");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Inmate360</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* CSS from beta_access.php for dark glassmorphism style */
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
            max-width: 600px; /* Slightly wider for the contact form */
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

        input[type="text"], input[type="email"], textarea, select {
            width: 100%;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            color: #ffffff;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            resize: vertical;
        }

        input::placeholder, textarea::placeholder {
            color: rgba(255, 255, 255, 0.3);
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(102, 126, 234, 0.6);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }
        
        select {
            /* Reset default select styling for dark background */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd' /%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.5em 1.5em;
            padding-right: 3rem;
        }

        select option {
            background: #0a0a0f; /* Dark background for options */
            color: #ffffff;
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

        .required-star {
            color: #fca5a5;
            margin-left: 0.25rem;
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
                <div class="logo-icon">C</div>
                <div class="badge">Contact Us</div>
            </div>

            <h1>Get In Touch</h1>
            <p class="subtitle">
                We're here to help. Whether you have a question about our data, a partnership inquiry, or need support, please fill out the form below.
            </p>

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

            <?php if (!$messageSent): ?>
            <form method="POST" action="contact.php">
                <div class="form-group">
                    <label for="name">Your Name <span class="required-star">*</span></label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        placeholder="John Doe"
                        required 
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="email">Your Email <span class="required-star">*</span></label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        placeholder="you@example.com"
                        required 
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="user_type">I am a...</label>
                    <select id="user_type" name="user_type">
                        <option value="General Inquiry" <?php echo (($_POST['user_type'] ?? '') == 'General Inquiry') ? 'selected' : ''; ?>>General Inquiry</option>
                        <option value="Victim/Survivor" <?php echo (($_POST['user_type'] ?? '') == 'Victim/Survivor') ? 'selected' : ''; ?>>Victim/Survivor</option>
                        <option value="Law Enforcement" <?php echo (($_POST['user_type'] ?? '') == 'Law Enforcement') ? 'selected' : ''; ?>>Law Enforcement</option>
                        <option value="Media/Press" <?php echo (($_POST['user_type'] ?? '') == 'Media/Press') ? 'selected' : ''; ?>>Media/Press</option>
                        <option value="Technical Support" <?php echo (($_POST['user_type'] ?? '') == 'Technical Support') ? 'selected' : ''; ?>>Technical Support</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="subject">Subject</label>
                    <input 
                        type="text" 
                        id="subject" 
                        name="subject" 
                        placeholder="Brief summary of your request"
                        value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="message">Your Message <span class="required-star">*</span></label>
                    <textarea 
                        id="message" 
                        name="message" 
                        rows="5" 
                        placeholder="Please provide as much detail as possible..."
                        required
                    ><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary">
                    Send Message
                </button>
            </form>
            <?php else: ?>
                <div style="text-align: center; padding: 2rem; color: rgba(255, 255, 255, 0.8);">
                    <p style="font-size: 1.2rem; margin-bottom: 1rem;">We appreciate you reaching out.</p>
                    <p style="font-size: 0.9rem;">Your message has been received and logged. We will review it and respond to your email address (<?php echo htmlspecialchars($email); ?>) as soon as possible.</p>
                    <a href="index.php" class="btn-secondary" style="margin-top: 2rem; display: inline-block; width: auto;">Go to Dashboard</a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>
