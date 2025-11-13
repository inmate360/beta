<?php
/**
 * API endpoint for fetching inmate details and court data
 */

require_once 'config.php';
require_once 'court_scraper.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$inmateId = $_GET['inmate_id'] ?? '';

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'get_inmate_details' && !empty($inmateId)) {
        // Fetch inmate details
        $stmt = $db->prepare("
            SELECT i.*,
                   GROUP_CONCAT(DISTINCT c.charge_description, '; ') as all_charges,
                   COUNT(DISTINCT c.id) as charge_count,
                   SUM(c.bond_amount) as total_bond
            FROM inmates i
            LEFT JOIN charges c ON i.inmate_id = c.inmate_id
            WHERE i.inmate_id = ?
            GROUP BY i.id
        ");
        $stmt->execute([$inmateId]);
        $inmate = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inmate) {
            echo json_encode(['success' => false, 'error' => 'Inmate not found']);
            exit;
        }

        // Get individual charges
        $chargesStmt = $db->prepare("
            SELECT charge_description, bond_amount, charge_level
            FROM charges
            WHERE inmate_id = ?
            ORDER BY id
        ");
        $chargesStmt->execute([$inmateId]);
        $charges = $chargesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get court cases
        $courtStmt = $db->prepare("
            SELECT *
            FROM court_cases
            WHERE defendant_name LIKE ?
            ORDER BY filing_date DESC
        ");

        // Extract last name for searching
        $nameParts = explode(',', $inmate['name']);
        $lastName = trim($nameParts[0]);

        $courtStmt->execute(['%' . $lastName . '%']);
        $courtCases = $courtStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'inmate' => $inmate,
            'charges' => $charges,
            'court_cases' => $courtCases
        ]);

    } elseif ($action === 'fetch_court_data' && !empty($inmateId)) {
        // Fetch inmate name
        $stmt = $db->prepare("SELECT name FROM inmates WHERE inmate_id = ?");
        $stmt->execute([$inmateId]);
        $inmate = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inmate) {
            echo json_encode(['success' => false, 'error' => 'Inmate not found']);
            exit;
        }

        // Parse name
        $nameParts = explode(',', $inmate['name']);
        $lastName = trim($nameParts[0]);
        $firstName = isset($nameParts[1]) ? trim($nameParts[1]) : '';

        // Search court records
        $scraper = new CourtScraper(false);
        $result = $scraper->searchByName($lastName, $firstName);

        echo json_encode($result);

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action or missing parameters']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>