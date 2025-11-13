<?php
/**
 * Enhanced JailTrak Setup Script
 * Sets up the enhanced database with court case integration
 */

echo "JailTrak Enhanced Setup\n";
echo "========================\n\n";

// Database path
$dbPath = __DIR__ . '/jailtrak.db';

try {
    echo "1. Connecting to database...\n";
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✓ Connected\n\n";

    echo "2. Loading enhanced schema...\n";

    // Load both schemas
    $baseSchema = file_get_contents(__DIR__ . '/schema.sql');
    $enhancedSchema = file_get_contents(__DIR__ . '/schema_enhanced.sql');

    echo "   ✓ Schemas loaded\n\n";

    echo "3. Creating/updating database tables...\n";

    // Execute base schema
    $db->exec($baseSchema);
    echo "   ✓ Base tables created\n";

    // Execute enhanced schema
    $db->exec($enhancedSchema);
    echo "   ✓ Enhanced tables created\n\n";

    echo "4. Verifying tables...\n";

    $tables = [
        'inmates',
        'charges',
        'scrape_logs',
        'court_cases',
        'inmate_court_cases',
        'court_case_charges',
        'court_events',
        'court_scrape_logs'
    ];

    foreach ($tables as $table) {
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($result->fetch()) {
            echo "   ✓ $table\n";
        } else {
            echo "   ✗ $table (MISSING!)\n";
        }
    }

    echo "\n5. Getting statistics...\n";

    $inmateCount = $db->query("SELECT COUNT(*) FROM inmates")->fetchColumn();
    $chargeCount = $db->query("SELECT COUNT(*) FROM charges")->fetchColumn();
    $courtCaseCount = $db->query("SELECT COUNT(*) FROM court_cases")->fetchColumn();
    $linkCount = $db->query("SELECT COUNT(*) FROM inmate_court_cases")->fetchColumn();

    echo "   - Inmates: $inmateCount\n";
    echo "   - Charges: $chargeCount\n";
    echo "   - Court Cases: $courtCaseCount\n";
    echo "   - Case Links: $linkCount\n\n";

    echo "========================\n";
    echo "Setup Complete!\n\n";
    echo "Next Steps:\n";
    echo "1. Run the jail scraper: php scraper.php --once\n";
    echo "2. Run the court scraper: php court_scraper.php\n";
    echo "3. Access the dashboard: index_enhanced.php\n\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
