<?php
/**
 * JailTrak - Automatically Link Inmates to Court Cases
 * Matches inmates to court cases based on name similarity
 */

require_once 'config.php';

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "========================================\n";
echo "JailTrak Auto-Link Inmates to Cases\n";
echo "========================================\n\n";

// Get all court cases that don't have linked inmates
$stmt = $db->query("
    SELECT c.* 
    FROM court_cases c
    LEFT JOIN inmate_court_cases icc ON c.id = icc.case_id
    WHERE icc.id IS NULL AND c.defendant_name IS NOT NULL
");
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($cases) . " cases without linked inmates\n\n";

$linkedCount = 0;

foreach ($cases as $case) {
    echo "Processing case: {$case['case_number']} - {$case['defendant_name']}\n";
    
    // Try to find matching inmate by name
    $nameParts = explode(' ', $case['defendant_name']);
    $lastName = end($nameParts);
    $firstName = $nameParts[0];
    
    // Search for inmates with matching name
    $stmt = $db->prepare("
        SELECT * FROM inmates
        WHERE name LIKE ? OR name LIKE ?
        ORDER BY booking_date DESC
        LIMIT 1
    ");
    $stmt->execute([
        "%$lastName%",
        "%$firstName% %$lastName%"
    ]);
    
    $inmate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($inmate) {
        // Calculate name similarity
        similar_text(
            strtolower($case['defendant_name']),
            strtolower($inmate['name']),
            $similarity
        );
        
        if ($similarity > 70) { // At least 70% match
            try {
                $stmt = $db->prepare("
                    INSERT INTO inmate_court_cases (inmate_id, case_id, relationship)
                    VALUES (?, ?, 'Defendant')
                ");
                $stmt->execute([$inmate['inmate_id'], $case['id']]);
                
                echo "  ✓ Linked to inmate: {$inmate['name']} ({$inmate['inmate_id']}) - {$similarity}% match\n";
                $linkedCount++;
            } catch (PDOException $e) {
                echo "  ✗ Error linking: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ⚠ Found match but similarity too low: {$inmate['name']} - {$similarity}%\n";
        }
    } else {
        echo "  - No matching inmate found\n";
    }
    
    echo "\n";
}

echo "========================================\n";
echo "Auto-Link Complete!\n";
echo "========================================\n\n";
echo "Successfully linked: $linkedCount cases\n\n";
?>