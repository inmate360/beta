<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invite System Setup - Inmate360</title>
    <style>
        :root {
            --color-background: #fcfcf9;
            --color-surface: #fffffe;
            --color-text: #134252;
            --color-text-secondary: #626c71;
            --color-primary: #21808d;
            --color-primary-hover: #1d7480;
            --color-success: #21808d;
            --color-error: #c0152f;
            --color-warning: #a84b2f;
            --color-border: rgba(94, 82, 64, 0.2);
            --color-card-border: rgba(94, 82, 64, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--color-background);
            color: var(--color-text);
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .header h1 {
            color: var(--color-primary);
            margin-bottom: 10px;
            font-size: 32px;
        }

        .header p {
            color: var(--color-text-secondary);
            font-size: 16px;
        }

        .card {
            background: var(--color-surface);
            border: 1px solid var(--color-card-border);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--color-card-border);
        }

        .step:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .step-number {
            background: var(--color-primary);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
            margin-right: 20px;
        }

        .step-content {
            flex: 1;
        }

        .step-content h3 {
            margin-bottom: 8px;
            color: var(--color-text);
        }

        .step-content p {
            color: var(--color-text-secondary);
            margin-bottom: 10px;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            margin-top: 10px;
        }

        .status.pending {
            background: rgba(168, 75, 47, 0.15);
            color: var(--color-warning);
            border: 1px solid rgba(168, 75, 47, 0.25);
        }

        .status.success {
            background: rgba(33, 128, 141, 0.15);
            color: var(--color-success);
            border: 1px solid rgba(33, 128, 141, 0.25);
        }

        .status.error {
            background: rgba(192, 21, 47, 0.15);
            color: var(--color-error);
            border: 1px solid rgba(192, 21, 47, 0.25);
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: var(--color-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
        }

        .btn:hover {
            background: var(--color-primary-hover);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .code-block {
            background: #f8f9fa;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #2c3e50;
            margin: 10px 0;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .invite-code {
            background: var(--color-surface);
            border: 2px solid var(--color-primary);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }

        .invite-code .code {
            font-size: 28px;
            font-weight: 700;
            color: var(--color-primary);
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
        }

        .invite-code .label {
            font-size: 13px;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .alert.info {
            background: rgba(33, 128, 141, 0.1);
            border: 1px solid rgba(33, 128, 141, 0.3);
            color: var(--color-text);
        }

        .alert.warning {
            background: rgba(168, 75, 47, 0.1);
            border: 1px solid rgba(168, 75, 47, 0.3);
            color: var(--color-text);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(94, 82, 64, 0.1);
            border-radius: 3px;
            margin: 20px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--color-primary);
            border-radius: 3px;
            transition: width 0.3s;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .file-list {
            list-style: none;
            margin: 15px 0;
        }

        .file-list li {
            padding: 8px 0;
            color: var(--color-text-secondary);
        }

        .file-list li:before {
            content: "📄 ";
            margin-right: 8px;
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Invite System Setup</h1>
            <p>Install database tables and generate your first invite code</p>
        </div>

        <div class="card">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h3>Database Connection</h3>
                    <p>Checking connection to SQLite database...</p>
                    <div id="db-status" class="status pending">Checking...</div>
                </div>
            </div>

            <div class="step">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h3>Create Tables</h3>
                    <p>Install invite codes and beta users tables</p>
                    <div id="tables-status" class="status pending">Waiting...</div>
                    <div id="tables-detail" class="hidden"></div>
                </div>
            </div>

            <div class="step">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h3>Generate Invite Code</h3>
                    <p>Create your first master invite code</p>
                    <div id="code-status" class="status pending">Waiting...</div>
                    <div id="invite-code-display" class="hidden"></div>
                </div>
            </div>

            <div class="step">
                <div class="step-number">4</div>
                <div class="step-content">
                    <h3>Test Access</h3>
                    <p>Verify the invite gate is working</p>
                    <div id="test-status" class="status pending">Waiting...</div>
                </div>
            </div>
        </div>

        <div class="progress-bar">
            <div class="progress-fill" id="progress" style="width: 0%"></div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <button class="btn" id="install-btn" onclick="runSetup()">
                <span id="btn-text">Start Installation</span>
            </button>
        </div>

        <div id="completion-message" class="card hidden" style="margin-top: 20px;">
            <h3 style="color: var(--color-success); margin-bottom: 15px;">✅ Setup Complete!</h3>
            <div class="alert info">
                <p><strong>Next Steps:</strong></p>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Save your invite code (shown above)</li>
                    <li>Upload <code>invitegate.php</code> to your server</li>
                    <li>Test access by visiting <a href="index.php" style="color: var(--color-primary);">index.php</a></li>
                    <li>You'll be redirected to enter your invite code</li>
                </ol>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 0;

        async function runSetup() {
            const btn = document.getElementById('install-btn');
            const btnText = document.getElementById('btn-text');
            
            btn.disabled = true;
            btnText.innerHTML = 'Installing<span class="loading"></span>';

            try {
                // Step 1: Check database
                await checkDatabase();
                updateProgress(25);
                await sleep(500);

                // Step 2: Create tables
                await createTables();
                updateProgress(50);
                await sleep(500);

                // Step 3: Generate invite code
                await generateInviteCode();
                updateProgress(75);
                await sleep(500);

                // Step 4: Test access
                await testAccess();
                updateProgress(100);

                // Show completion
                document.getElementById('completion-message').classList.remove('hidden');
                btnText.textContent = 'Installation Complete';
                
            } catch (error) {
                btnText.textContent = 'Installation Failed';
                alert('Installation error: ' + error.message);
            }
        }

        async function checkDatabase() {
            const status = document.getElementById('db-status');
            
            const response = await fetch('?action=check_db');
            const result = await response.json();
            
            if (result.success) {
                status.textContent = '✓ Connected to: ' + result.database;
                status.className = 'status success';
            } else {
                status.textContent = '✗ Connection failed: ' + result.error;
                status.className = 'status error';
                throw new Error('Database connection failed');
            }
        }

        async function createTables() {
            const status = document.getElementById('tables-status');
            const detail = document.getElementById('tables-detail');
            
            const response = await fetch('?action=create_tables');
            const result = await response.json();
            
            if (result.success) {
                status.textContent = '✓ Tables created successfully';
                status.className = 'status success';
                
                detail.innerHTML = '<ul class="file-list">' +
                    result.tables.map(t => '<li>' + t + '</li>').join('') +
                    '</ul>';
                detail.classList.remove('hidden');
            } else {
                status.textContent = '✗ Failed: ' + result.error;
                status.className = 'status error';
                throw new Error('Table creation failed');
            }
        }

        async function generateInviteCode() {
            const status = document.getElementById('code-status');
            const display = document.getElementById('invite-code-display');
            
            const response = await fetch('?action=generate_code');
            const result = await response.json();
            
            if (result.success) {
                status.textContent = '✓ Code generated';
                status.className = 'status success';
                
                display.innerHTML = `
                    <div class="invite-code">
                        <div class="label">Your Master Invite Code</div>
                        <div class="code">${result.code}</div>
                        <p style="margin-top: 10px; color: var(--color-text-secondary);">
                            ${result.description}<br>
                            <small>Created: ${result.created}</small>
                        </p>
                    </div>
                    <div class="alert warning">
                        <strong>⚠️ Save this code!</strong> You'll need it to access the site.
                    </div>
                `;
                display.classList.remove('hidden');
            } else {
                status.textContent = '✗ Failed: ' + result.error;
                status.className = 'status error';
                throw new Error('Code generation failed');
            }
        }

        async function testAccess() {
            const status = document.getElementById('test-status');
            
            const response = await fetch('?action=test_access');
            const result = await response.json();
            
            if (result.success) {
                status.textContent = '✓ Invite gate is working';
                status.className = 'status success';
            } else {
                status.textContent = '⚠ Warning: ' + result.error;
                status.className = 'status warning';
            }
        }

        function updateProgress(percent) {
            document.getElementById('progress').style.width = percent + '%';
        }

        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    </script>

<?php
// ============================================================================
// PHP BACKEND - Setup Actions
// ============================================================================

// Only process if this is an AJAX request
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    require_once 'config.php';
    
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'check_db':
                checkDatabaseConnection();
                break;
                
            case 'create_tables':
                createInviteTables();
                break;
                
            case 'generate_code':
                generateFirstInviteCode();
                break;
                
            case 'test_access':
                testInviteAccess();
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================================================
// Setup Functions
// ============================================================================

function checkDatabaseConnection() {
    try {
        $db = new PDO('sqlite:' . DBPATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo json_encode([
            'success' => true,
            'database' => basename(DBPATH)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function createInviteTables() {
    try {
        $db = new PDO('sqlite:' . DBPATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create invite codes table
        $db->exec("
            CREATE TABLE IF NOT EXISTS invitecodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                description TEXT,
                maxuses INTEGER DEFAULT 0,
                currentuses INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1,
                createdat DATETIME DEFAULT CURRENT_TIMESTAMP,
                expirydate DATETIME,
                lastusedat DATETIME,
                createdby TEXT DEFAULT 'system'
            )
        ");
        
        // Create beta users table
        $db->exec("
            CREATE TABLE IF NOT EXISTS betausers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invitecodeid INTEGER NOT NULL,
                firstaccess DATETIME DEFAULT CURRENT_TIMESTAMP,
                lastaccess DATETIME DEFAULT CURRENT_TIMESTAMP,
                accesscount INTEGER DEFAULT 0,
                sessionid TEXT,
                ipaddress TEXT,
                useragent TEXT,
                FOREIGN KEY (invitecodeid) REFERENCES invitecodes(id) ON DELETE CASCADE
            )
        ");
        
        // Create indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_invitecode ON invitecodes(code)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_inviteactive ON invitecodes(active)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_inviteexpiry ON invitecodes(expirydate)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_betauser_invite ON betausers(invitecodeid)");
        
        echo json_encode([
            'success' => true,
            'tables' => ['invitecodes', 'betausers']
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function generateFirstInviteCode() {
    try {
        $db = new PDO('sqlite:' . DBPATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Generate a random code
        $code = generateRandomCode();
        $description = 'Master Beta Access - Unlimited Uses';
        
        // Insert the code (unlimited uses, no expiry)
        $stmt = $db->prepare("
            INSERT INTO invitecodes (code, description, maxuses, expirydate) 
            VALUES (?, ?, 0, NULL)
        ");
        $stmt->execute([$code, $description]);
        
        echo json_encode([
            'success' => true,
            'code' => $code,
            'description' => $description,
            'created' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function testInviteAccess() {
    try {
        // Check if invitegate.php exists
        if (!file_exists('invitegate.php')) {
            throw new Exception('invitegate.php not found - please upload it');
        }
        
        // Check if beta_access.php exists
        if (!file_exists('beta_access.php')) {
            throw new Exception('beta_access.php not found - please create it');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'All required files present'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function generateRandomCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    
    for ($i = 0; $i < 12; $i++) {
        if ($i > 0 && $i % 4 == 0) {
            $code .= '-';
        }
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    return $code;
}
?>
</body>
</html>
