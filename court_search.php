<?php
/**
 * AJAX endpoint for searching court records by name.
 * Accepts GET parameters:
 *  - fname (optional)
 *  - lname (required)
 *  - inmate_id (optional) -- used for linking context only
 *
 * Returns JSON:
 * {
 *   success: true,
 *   results: [
 *     { case_number, defendant_name, offense, filing_date, judge, similarity }
 *   ]
 * }
 */

require_once 'config.php';
require_once __DIR__ . '/court_scraper.php';

header('Content-Type: application/json; charset=utf-8');

$first = trim($_GET['fname'] ?? '');
$last = trim($_GET['lname'] ?? '');
$inmateId = trim($_GET['inmate_id'] ?? '');

if (empty($last)) {
    echo json_encode(['success' => false, 'error' => 'Last name (lname) is required.']);
    exit;
}

try {
    // debug disabled for production endpoint
    $scraper = new CourtScraper(false);
    $rawResults = $scraper->searchByName($first, $last);

    $filtered = [];
    $targetName = trim($first . ' ' . $last);
    $threshold = defined('AUTO_LINK_SIMILARITY_THRESHOLD') ? (int)AUTO_LINK_SIMILARITY_THRESHOLD : 70;

    foreach ($rawResults as $r) {
        $defName = $r['defendant_name'] ?? '';
        $similarity = 0;
        if (!empty($defName) && !empty($targetName)) {
            similar_text(strtolower($defName), strtolower($targetName), $similarity);
        }
        $r['similarity'] = round($similarity, 2);
        $filtered[] = $r;
    }

    // Sort by similarity desc, then filing_date desc
    usort($filtered, function($a, $b) {
        $s1 = $a['similarity'] ?? 0;
        $s2 = $b['similarity'] ?? 0;
        if ($s1 === $s2) {
            return strcmp($b['filing_date'] ?? '', $a['filing_date'] ?? '');
        }
        return ($s2 <=> $s1);
    });

    echo json_encode(['success' => true, 'results' => $filtered]);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}