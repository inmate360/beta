<?php
require_once 'config.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$inmateId = $input['inmate_id'] ?? '';
$firstName = $input['first_name'] ?? '';
$lastName = $input['last_name'] ?? '';

if (empty($lastName)) {
    echo json_encode(['error' => 'Last name is required']);
    exit;
}

try {
    $scraper = new CourtScraper();
    $cases = $scraper->searchByName($firstName, $lastName);
    
    if ($cases) {
        // Auto-link cases with high name similarity
        foreach ($cases as $case) {
            similar_text(
                strtolower($case['defendant_name']), 
                strtolower("$firstName $lastName"), 
                $similarity
            );
            
            if ($similarity > 80) {
                // Link the case to the inmate
                $db = new PDO('sqlite:' . DB_PATH);
                $stmt = $db->prepare("
                    INSERT OR IGNORE INTO inmate_court_cases 
                    (inmate_id, case_number, link_confidence) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$inmateId, $case['case_number'], $similarity/100]);
            }
        }
    }
    
    echo json_encode($cases);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}