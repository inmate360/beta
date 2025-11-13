<?php
/**
 * Background job to pre-fetch all inmate details
 * Can be run via cron: php fetch_all_inmates.php
 */

require_once 'config.php';
require_once 'lib/inmate_fetcher.php';

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get all inmates that need fetching
$stmt = $db->prepare("
    SELECT DISTINCT inmate_id, le_number FROM inmates
    WHERE name IS NULL OR name = 'Inmate details'
    LIMIT 50
");
$stmt->execute();
$inmates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fetcher = new InmateFetcher();
$successCount = 0;
$failCount = 0;

foreach ($inmates as $inmate) {
    $result = $fetcher->fetch($inmate['inmate_id'], $inmate['le_number'] ?? '');
    if ($result['success']) {
        $successCount++;
        echo "✓ Fetched: {$inmate['inmate_id']}\n";
    } else {
        $failCount++;
        echo "✗ Failed: {$inmate['inmate_id']} - {$result['message']}\n";
    }
    sleep(1); // Be nice to remote server
}

echo "\nTotal: Success=$successCount, Failed=$failCount\n";
?>