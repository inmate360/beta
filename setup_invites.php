<?php
/**
 * JailTrak Invite System Setup Script
 * Initializes the invite code database tables
 */

require_once 'config.php';

echo "========================================\n";
echo "JailTrak Invite System Setup\n";
echo "========================================\n\n";

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Reading schema...\n";
    $schema = file_get_contents(__DIR__ . '/invite_schema.sql');
    
    echo "Creating invite tables...\n";
    $db->exec($schema);
    
    echo "✓ Invite tables created successfully!\n\n";
    
    // Show default codes
    $codes = $db->query("SELECT code, description, max_uses FROM invite_codes WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "========================================\n";
    echo "Default Invite Codes Created:\n";
    echo "========================================\n\n";
    
    foreach ($codes as $code) {
        echo "Code: {$code['code']}\n";
        echo "Purpose: {$code['description']}\n";
        echo "Max Uses: " . ($code['max_uses'] == -1 ? 'Unlimited' : $code['max_uses']) . "\n";
        echo "----------------------------------------\n";
    }
    
    echo "\nSetup complete!\n\n";
    echo "Next steps:\n";
    echo "1. Visit beta_access.php to test invite code entry\n";
    echo "2. Visit admin_invites.php to manage codes (password: admin123)\n";
    echo "3. Share invite codes with beta testers\n\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>