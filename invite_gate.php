<?php
/**
 * Inmate360 Invite Gate
 * Handles invite code verification and access control
 */

session_start();
require_once 'config.php';

// Check if user has valid invite code in session
function checkInviteAccess() {
    if (!isset($_SESSION['invite_verified']) || $_SESSION['invite_verified'] !== true) {
        header('Location: beta_access.php');
        exit;
    }
}

// Validate invite code
function validateInviteCode($code) {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->prepare("
            SELECT ic.*, COUNT(iul.id) as usage_count
            FROM invite_codes ic
            LEFT JOIN invite_usage_log iul ON ic.id = iul.invite_code_id
            WHERE ic.code = ? AND ic.active = 1 
            AND (ic.max_uses = -1 OR ic.uses < ic.max_uses)
            AND (ic.expires_at IS NULL OR ic.expires_at > datetime('now'))
            GROUP BY ic.id
        ");
        $stmt->execute([strtoupper($code)]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($invite) {
            // Log the access attempt
            $stmt = $db->prepare("
                INSERT INTO invite_usage_log (invite_code_id, ip_address, user_agent, session_id, success)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $invite['id'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                session_id()
            ]);
            
            return true;
        }
        
        // Log failed attempt
        $stmt = $db->prepare("
            INSERT INTO invite_usage_log (invite_code_id, ip_address, user_agent, session_id, success, error_message)
            SELECT ic.id, ?, ?, ?, 0, 'Invalid or expired code'
            FROM invite_codes ic WHERE ic.code = ?
        ");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            session_id(),
            strtoupper($code)
        ]);
        
        return false;
    } catch (PDOException $e) {
        error_log("Invite code validation error: " . $e->getMessage());
        return false;
    }
}

// Check if invite system is enabled
function isInviteRequired() {
    return REQUIRE_INVITE;
}

// Get invite code statistics
function getInviteStats() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $stats = [
            'total_codes' => $db->query("SELECT COUNT(*) FROM invite_codes WHERE active = 1")->fetchColumn(),
            'used_today' => $db->query("SELECT COUNT(*) FROM invite_usage_log WHERE date(used_at) = date('now') AND success = 1")->fetchColumn(),
            'total_users' => $db->query("SELECT COUNT(*) FROM beta_users")->fetchColumn(),
            'active_users' => $db->query("SELECT COUNT(*) FROM beta_users WHERE last_access >= datetime('now', '-7 days')")->fetchColumn(),
        ];
        return $stats;
    } catch (Exception $e) {
        return ['total_codes' => 0, 'used_today' => 0, 'total_users' => 0, 'active_users' => 0];
    }
}

// Log user activity
function logUserActivity($activity_type, $description = '', $email = null) {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $stmt = $db->prepare("
            INSERT INTO user_activity_log (email, activity_type, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $email,
            $activity_type,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Silently fail if logging fails
    }
}
?>