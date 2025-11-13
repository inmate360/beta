-- ============================================
-- Inmate360 COMPLETE Database Schema
-- All Platform Tables - Unified Setup
-- Includes: Invite System + Jail Data + Court Data
-- ============================================

-- ============================================
-- CLEANUP (Drop in correct order due to foreign keys)
-- ============================================
DROP TABLE IF EXISTS user_activity_log;
DROP TABLE IF EXISTS system_access_log;
DROP TABLE IF EXISTS beta_users;
DROP TABLE IF EXISTS invite_usage_log;
DROP TABLE IF EXISTS invite_codes;
DROP TABLE IF EXISTS scrape_logs;
DROP TABLE IF EXISTS inmate_detail_urls;
DROP TABLE IF EXISTS charges;
DROP TABLE IF EXISTS inmates;
DROP TABLE IF EXISTS court_scrape_logs;
DROP TABLE IF EXISTS court_case_activity_log;
DROP TABLE IF EXISTS court_events;
DROP TABLE IF EXISTS court_charges;
DROP TABLE IF EXISTS court_cases;
DROP TABLE IF EXISTS court_stats;
DROP TABLE IF EXISTS inmate_court_cases;

-- Drop views
DROP VIEW IF EXISTS active_invite_codes;
DROP VIEW IF EXISTS beta_user_stats;
DROP VIEW IF EXISTS popular_invite_codes;

-- ============================================
-- INVITE SYSTEM TABLES
-- ============================================

-- Invite Codes Table
CREATE TABLE invite_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    description TEXT,
    created_by TEXT DEFAULT 'System',
    max_uses INTEGER DEFAULT -1,
    uses INTEGER DEFAULT 0,
    active INTEGER DEFAULT 1,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME,
    notes TEXT
);

-- Invite Usage Log
CREATE TABLE invite_usage_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    session_id TEXT,
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    success INTEGER DEFAULT 1,
    error_message TEXT,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE CASCADE
);

-- Beta Users Table
CREATE TABLE beta_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER,
    email TEXT UNIQUE,
    name TEXT,
    organization TEXT,
    role TEXT DEFAULT 'user',
    access_level TEXT DEFAULT 'read',
    first_access DATETIME,
    last_access DATETIME,
    access_count INTEGER DEFAULT 0,
    feedback TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE SET NULL
);

-- User Activity Log
CREATE TABLE user_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    email TEXT,
    activity_type TEXT NOT NULL,
    description TEXT,
    ip_address TEXT,
    user_agent TEXT,
    activity_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES beta_users(id) ON DELETE CASCADE
);

-- System Access Logs
CREATE TABLE system_access_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_type TEXT NOT NULL,
    user_email TEXT,
    ip_address TEXT,
    user_agent TEXT,
    success INTEGER DEFAULT 1,
    error_message TEXT,
    access_date DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- JAIL DATA TABLES
-- ============================================

-- Inmates Table (UPDATED: docket_number as primary identifier)
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
-- COURT DATA TABLES
-- ============================================

-- Court Cases Table
CREATE TABLE court_cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_number TEXT UNIQUE NOT NULL,
    file_date TEXT,
    case_type TEXT,
    case_status TEXT,
    defendant_first_name TEXT,
    defendant_middle_name TEXT,
    defendant_last_name TEXT,
    defendant_suffix TEXT,
    defendant_full_name TEXT,
    attorney_name TEXT,
    prosecutor_name TEXT,
    judge_name TEXT,
    court_room TEXT,
    next_court_date TEXT,
    disposition TEXT,
    disposition_date TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Court Charges Table
CREATE TABLE court_charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id INTEGER NOT NULL,
    case_number TEXT NOT NULL,
    charge_sequence INTEGER,
    charge_description TEXT,
    charge_code TEXT,
    charge_type TEXT,
    charge_degree TEXT,
    charge_class TEXT,
    plea TEXT,
    disposition TEXT,
    sentence TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Events Table
CREATE TABLE court_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id INTEGER NOT NULL,
    case_number TEXT NOT NULL,
    event_date TEXT,
    event_type TEXT,
    event_description TEXT,
    event_result TEXT,
    next_event_date TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Case Activity Log Table
CREATE TABLE court_case_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_number TEXT NOT NULL,
    activity_type TEXT NOT NULL,
    activity_date TEXT,
    activity_description TEXT,
    performed_by TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Inmate-Court Case Link Table
CREATE TABLE inmate_court_cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inmate_id TEXT NOT NULL,
    case_number TEXT NOT NULL,
    link_type TEXT DEFAULT 'matched',
    confidence_score INTEGER DEFAULT 100,
    linked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    linked_by TEXT DEFAULT 'system',
    notes TEXT,
    FOREIGN KEY (inmate_id) REFERENCES inmates(docket_number) ON DELETE CASCADE,
    FOREIGN KEY (case_number) REFERENCES court_cases(case_number) ON DELETE CASCADE,
    UNIQUE(inmate_id, case_number)
);

-- Court Statistics Table
CREATE TABLE court_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stat_date DATE NOT NULL,
    total_cases INTEGER DEFAULT 0,
    active_cases INTEGER DEFAULT 0,
    closed_cases INTEGER DEFAULT 0,
    new_cases_today INTEGER DEFAULT 0,
    total_charges INTEGER DEFAULT 0,
    total_events INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Court Scrape Logs Table
CREATE TABLE court_scrape_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    scrape_type TEXT,
    cases_found INTEGER DEFAULT 0,
    cases_updated INTEGER DEFAULT 0,
    status TEXT,
    message TEXT,
    error_details TEXT
);

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

-- Invite System Indexes
CREATE INDEX idx_invite_code ON invite_codes(code);
CREATE INDEX idx_invite_active ON invite_codes(active);
CREATE INDEX idx_invite_created_by ON invite_codes(created_by);
CREATE INDEX idx_invite_expires ON invite_codes(expires_at);
CREATE INDEX idx_usage_code ON invite_usage_log(invite_code_id);
CREATE INDEX idx_usage_time ON invite_usage_log(used_at);
CREATE INDEX idx_usage_ip ON invite_usage_log(ip_address);
CREATE INDEX idx_beta_email ON beta_users(email);
CREATE INDEX idx_beta_invite ON beta_users(invite_code_id);
CREATE INDEX idx_beta_role ON beta_users(role);
CREATE INDEX idx_beta_last_access ON beta_users(last_access);
CREATE INDEX idx_user_activity_user ON user_activity_log(user_id);
CREATE INDEX idx_user_activity_email ON user_activity_log(email);
CREATE INDEX idx_user_activity_type ON user_activity_log(activity_type);
CREATE INDEX idx_user_activity_date ON user_activity_log(activity_date);
CREATE INDEX idx_system_access_type ON system_access_log(access_type);
CREATE INDEX idx_system_access_date ON system_access_log(access_date);

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
CREATE INDEX idx_court_cases_number ON court_cases(case_number);
CREATE INDEX idx_court_cases_defendant ON court_cases(defendant_full_name);
CREATE INDEX idx_court_cases_file_date ON court_cases(file_date);
CREATE INDEX idx_court_cases_status ON court_cases(case_status);
CREATE INDEX idx_court_cases_type ON court_cases(case_type);
CREATE INDEX idx_court_charges_case ON court_charges(case_id);
CREATE INDEX idx_court_charges_number ON court_charges(case_number);
CREATE INDEX idx_court_events_case ON court_events(case_id);
CREATE INDEX idx_court_events_number ON court_events(case_number);
CREATE INDEX idx_court_events_date ON court_events(event_date);
CREATE INDEX idx_court_activity_number ON court_case_activity_log(case_number);
CREATE INDEX idx_court_activity_date ON court_case_activity_log(activity_date);
CREATE INDEX idx_inmate_court_inmate ON inmate_court_cases(inmate_id);
CREATE INDEX idx_inmate_court_case ON inmate_court_cases(case_number);

-- ============================================
-- TRIGGERS FOR AUTO-UPDATES
-- ============================================

-- Invite system triggers
CREATE TRIGGER update_invite_timestamp
AFTER UPDATE ON invite_codes
FOR EACH ROW
BEGIN
    UPDATE invite_codes SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER update_beta_user_timestamp
AFTER UPDATE ON beta_users
FOR EACH ROW
BEGIN
    UPDATE beta_users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER log_invite_usage
AFTER INSERT ON invite_usage_log
FOR EACH ROW
WHEN NEW.success = 1
BEGIN
    UPDATE invite_codes SET
        uses = uses + 1,
        last_used_at = NEW.used_at,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.invite_code_id;
END;

CREATE TRIGGER log_beta_user_access
AFTER UPDATE ON beta_users
FOR EACH ROW
WHEN NEW.last_access != OLD.last_access
BEGIN
    UPDATE beta_users SET access_count = access_count + 1 WHERE id = NEW.id;
END;

-- Inmates table trigger
CREATE TRIGGER update_inmates_timestamp
AFTER UPDATE ON inmates
FOR EACH ROW
BEGIN
    UPDATE inmates SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Court cases trigger
CREATE TRIGGER update_court_cases_timestamp
AFTER UPDATE ON court_cases
FOR EACH ROW
BEGIN
    UPDATE court_cases SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- ============================================
-- VIEWS FOR COMMON QUERIES
-- ============================================

CREATE VIEW active_invite_codes AS
SELECT * FROM invite_codes
WHERE active = 1 AND (max_uses = -1 OR uses < max_uses)
      AND (expires_at IS NULL OR expires_at > datetime('now'))
ORDER BY created_at DESC;

CREATE VIEW beta_user_stats AS
SELECT
    COUNT(*) as total_users,
    COUNT(CASE WHEN first_access >= date('now', '-7 days') THEN 1 END) as new_users_week,
    COUNT(CASE WHEN last_access >= date('now', '-1 day') THEN 1 END) as active_users_day,
    AVG(access_count) as avg_access_count
FROM beta_users;

CREATE VIEW popular_invite_codes AS
SELECT ic.*, COUNT(iul.id) as total_uses
FROM invite_codes ic
LEFT JOIN invite_usage_log iul ON ic.id = iul.invite_code_id
GROUP BY ic.id
ORDER BY total_uses DESC;

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Insert default invite codes
INSERT INTO invite_codes (code, description, created_by, max_uses, active, notes) VALUES
('BETA-2025-LAUNCH', 'Initial beta launch code - unlimited uses', 'System', -1, 1, 'Primary beta access code'),
('ADMIN-ACCESS', 'Admin setup code - unlimited uses', 'System', -1, 1, 'For system administrators'),
('TESTING-123', 'Testing code - 10 uses', 'System', 10, 1, 'For testing purposes');

-- ============================================
-- VERIFICATION
-- ============================================

-- Count tables
SELECT 'Total tables created: ' || COUNT(*) as status 
FROM sqlite_master 
WHERE type = 'table';

-- Verify inmates table structure
PRAGMA table_info(inmates);

-- Verify court_cases table structure
PRAGMA table_info(court_cases);