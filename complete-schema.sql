-- ============================================
-- Inmate360 Complete Database Schema
-- Unified setup for all platform tables
-- ============================================

-- Drop existing tables if they exist (for clean setup)
DROP TABLE IF EXISTS scrape_logs;
DROP TABLE IF EXISTS inmate_detail_urls;
DROP TABLE IF EXISTS charges;
DROP TABLE IF EXISTS inmates;
DROP TABLE IF EXISTS user_activity_log;
DROP TABLE IF EXISTS beta_users;
DROP TABLE IF EXISTS invite_usage_log;
DROP TABLE IF EXISTS invite_codes;

-- ============================================
-- INVITE SYSTEM TABLES
-- ============================================

-- Invite Codes Table
CREATE TABLE invite_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    created_by TEXT,
    max_uses INTEGER DEFAULT -1,
    uses INTEGER DEFAULT 0,
    expires_at DATETIME,
    active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Invite Usage Log
CREATE TABLE invite_usage_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    session_id TEXT,
    success INTEGER DEFAULT 1,
    error_message TEXT,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE CASCADE
);

-- Beta Users Table
CREATE TABLE beta_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER NOT NULL,
    email TEXT,
    first_access DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_access DATETIME DEFAULT CURRENT_TIMESTAMP,
    access_count INTEGER DEFAULT 0,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE CASCADE
);

-- User Activity Log
CREATE TABLE user_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT,
    activity_type TEXT NOT NULL,
    description TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- JAIL DATA TABLES
-- ============================================

-- Inmates Table (Updated schema with docket_number as primary identifier)
CREATE TABLE inmates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    docket_number TEXT UNIQUE NOT NULL,
    inmate_id TEXT,
    name TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    age INTEGER,
    sex TEXT,
    race TEXT,
    height TEXT,
    weight TEXT,
    hair_color TEXT,
    eye_color TEXT,
    le_number TEXT,
    booking_date TEXT,
    booking_time TEXT,
    release_date TEXT,
    release_time TEXT,
    bond_amount TEXT,
    arresting_agency TEXT DEFAULT 'Clayton County Sheriff''s Office',
    booking_officer TEXT,
    facility_location TEXT DEFAULT 'Clayton County Jail',
    cell_block TEXT,
    classification TEXT,
    in_jail INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Charges Table
CREATE TABLE charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inmate_id TEXT NOT NULL,
    charge_description TEXT NOT NULL,
    charge_type TEXT,
    charge_code TEXT,
    court_case_number TEXT,
    charge_date TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inmate_id) REFERENCES inmates(docket_number) ON DELETE CASCADE
);

-- Inmate Detail URLs Table
CREATE TABLE inmate_detail_urls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inmate_id TEXT UNIQUE NOT NULL,
    detail_url TEXT NOT NULL,
    scraped INTEGER DEFAULT 0,
    scrape_attempts INTEGER DEFAULT 0,
    last_scrape_attempt DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inmate_id) REFERENCES inmates(docket_number) ON DELETE CASCADE
);

-- Scrape Logs Table
CREATE TABLE scrape_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    inmates_found INTEGER DEFAULT 0,
    status TEXT,
    message TEXT,
    error_details TEXT
);

-- ============================================
-- COURT DATA TABLES (for future expansion)
-- ============================================

-- Cases Table
CREATE TABLE IF NOT EXISTS cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_number TEXT UNIQUE NOT NULL,
    file_date TEXT,
    case_type TEXT,
    case_status TEXT,
    defendant_name TEXT,
    docket_number TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

-- Invite System Indexes
CREATE INDEX idx_invite_codes_code ON invite_codes(code);
CREATE INDEX idx_invite_codes_active ON invite_codes(active);
CREATE INDEX idx_invite_usage_code_id ON invite_usage_log(invite_code_id);
CREATE INDEX idx_invite_usage_success ON invite_usage_log(success);
CREATE INDEX idx_beta_users_invite ON beta_users(invite_code_id);
CREATE INDEX idx_activity_log_email ON user_activity_log(email);
CREATE INDEX idx_activity_log_type ON user_activity_log(activity_type);

-- Jail Data Indexes
CREATE INDEX idx_inmates_docket ON inmates(docket_number);
CREATE INDEX idx_inmates_inmate_id ON inmates(inmate_id);
CREATE INDEX idx_inmates_name ON inmates(name);
CREATE INDEX idx_inmates_first_name ON inmates(first_name);
CREATE INDEX idx_inmates_last_name ON inmates(last_name);
CREATE INDEX idx_inmates_le_number ON inmates(le_number);
CREATE INDEX idx_inmates_booking_date ON inmates(booking_date);
CREATE INDEX idx_inmates_in_jail ON inmates(in_jail);
CREATE INDEX idx_inmates_arresting_agency ON inmates(arresting_agency);
CREATE INDEX idx_inmates_facility ON inmates(facility_location);
CREATE INDEX idx_charges_inmate ON charges(inmate_id);
CREATE INDEX idx_charges_type ON charges(charge_type);
CREATE INDEX idx_charges_description ON charges(charge_description);
CREATE INDEX idx_detail_urls_inmate ON inmate_detail_urls(inmate_id);
CREATE INDEX idx_detail_urls_scraped ON inmate_detail_urls(scraped);

-- Court Data Indexes
CREATE INDEX idx_cases_number ON cases(case_number);
CREATE INDEX idx_cases_docket ON cases(docket_number);
CREATE INDEX idx_cases_defendant ON cases(defendant_name);
CREATE INDEX idx_cases_date ON cases(file_date);

-- ============================================
-- TRIGGERS FOR AUTO-UPDATES
-- ============================================

-- Update invite code usage count when new usage is logged
CREATE TRIGGER update_invite_uses
AFTER INSERT ON invite_usage_log
WHEN NEW.success = 1
BEGIN
    UPDATE invite_codes 
    SET uses = uses + 1,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.invite_code_id;
END;

-- Update inmates timestamp on any change
CREATE TRIGGER update_inmates_timestamp
AFTER UPDATE ON inmates
BEGIN
    UPDATE inmates 
    SET updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.id;
END;

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Insert default invite codes for testing
INSERT INTO invite_codes (code, max_uses, created_by) VALUES 
('BETA-2025-LAUNCH', 100, 'system'),
('ADMIN-ACCESS', -1, 'system'),
('TESTING-123', 10, 'system');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Count all tables
SELECT 'Total tables created: ' || COUNT(*) as status 
FROM sqlite_master 
WHERE type = 'table';

-- Verify inmates table structure
PRAGMA table_info(inmates);