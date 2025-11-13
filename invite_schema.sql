-- Inmate360 Invite System Database Schema
-- Enhanced Beta Access and User Management

-- Invite Codes Table - Enhanced
CREATE TABLE IF NOT EXISTS invite_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    description TEXT,
    created_by TEXT DEFAULT 'System',
    max_uses INTEGER DEFAULT -1, -- -1 for unlimited
    uses INTEGER DEFAULT 0,
    active INTEGER DEFAULT 1,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME,
    notes TEXT
);

-- Invite Usage Log - Enhanced
CREATE TABLE IF NOT EXISTS invite_usage_log (
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
CREATE TABLE IF NOT EXISTS beta_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_code_id INTEGER,
    email TEXT UNIQUE,
    name TEXT,
    organization TEXT,
    role TEXT DEFAULT 'user',
    access_level TEXT DEFAULT 'read', -- read, write, admin
    first_access DATETIME,
    last_access DATETIME,
    access_count INTEGER DEFAULT 0,
    feedback TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invite_code_id) REFERENCES invite_codes(id) ON DELETE SET NULL
);

-- User Activity Log
CREATE TABLE IF NOT EXISTS user_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    email TEXT,
    activity_type TEXT NOT NULL, -- login, search, view_inmate, view_case, etc.
    description TEXT,
    ip_address TEXT,
    user_agent TEXT,
    activity_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES beta_users(id) ON DELETE CASCADE
);

-- System Access Logs
CREATE TABLE IF NOT EXISTS system_access_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_type TEXT NOT NULL, -- admin_login, scraper_run, etc.
    user_email TEXT,
    ip_address TEXT,
    user_agent TEXT,
    success INTEGER DEFAULT 1,
    error_message TEXT,
    access_date DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_invite_code ON invite_codes(code);
CREATE INDEX IF NOT EXISTS idx_invite_active ON invite_codes(active);
CREATE INDEX IF NOT EXISTS idx_invite_created_by ON invite_codes(created_by);
CREATE INDEX IF NOT EXISTS idx_invite_expires ON invite_codes(expires_at);

CREATE INDEX IF NOT EXISTS idx_usage_code ON invite_usage_log(invite_code_id);
CREATE INDEX IF NOT EXISTS idx_usage_time ON invite_usage_log(used_at);
CREATE INDEX IF NOT EXISTS idx_usage_ip ON invite_usage_log(ip_address);

CREATE INDEX IF NOT EXISTS idx_beta_email ON beta_users(email);
CREATE INDEX IF NOT EXISTS idx_beta_invite ON beta_users(invite_code_id);
CREATE INDEX IF NOT EXISTS idx_beta_role ON beta_users(role);
CREATE INDEX IF NOT EXISTS idx_beta_last_access ON beta_users(last_access);

CREATE INDEX IF NOT EXISTS idx_user_activity_user ON user_activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_user_activity_email ON user_activity_log(email);
CREATE INDEX IF NOT EXISTS idx_user_activity_type ON user_activity_log(activity_type);
CREATE INDEX IF NOT EXISTS idx_user_activity_date ON user_activity_log(activity_date);

CREATE INDEX IF NOT EXISTS idx_system_access_type ON system_access_log(access_type);
CREATE INDEX IF NOT EXISTS idx_system_access_date ON system_access_log(access_date);

-- Triggers for automatic updates
CREATE TRIGGER IF NOT EXISTS update_invite_timestamp
    AFTER UPDATE ON invite_codes
    FOR EACH ROW
    BEGIN
        UPDATE invite_codes SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_beta_user_timestamp
    AFTER UPDATE ON beta_users
    FOR EACH ROW
    BEGIN
        UPDATE beta_users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS log_invite_usage
    AFTER INSERT ON invite_usage_log
    FOR EACH ROW
    BEGIN
        UPDATE invite_codes SET
            uses = uses + 1,
            last_used_at = NEW.used_at,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = NEW.invite_code_id;
    END;

CREATE TRIGGER IF NOT EXISTS log_beta_user_access
    AFTER UPDATE ON beta_users
    FOR EACH ROW
    WHEN NEW.last_access != OLD.last_access
    BEGIN
        UPDATE beta_users SET access_count = access_count + 1 WHERE id = NEW.id;
    END;

-- Views for common queries
CREATE VIEW IF NOT EXISTS active_invite_codes AS
    SELECT * FROM invite_codes
    WHERE active = 1 AND (max_uses = -1 OR uses < max_uses)
          AND (expires_at IS NULL OR expires_at > datetime('now'))
    ORDER BY created_at DESC;

CREATE VIEW IF NOT EXISTS beta_user_stats AS
    SELECT
        COUNT(*) as total_users,
        COUNT(CASE WHEN first_access >= date('now', '-7 days') THEN 1 END) as new_users_week,
        COUNT(CASE WHEN last_access >= date('now', '-1 day') THEN 1 END) as active_users_day,
        AVG(access_count) as avg_access_count
    FROM beta_users;

CREATE VIEW IF NOT EXISTS popular_invite_codes AS
    SELECT ic.*, COUNT(iul.id) as total_uses
    FROM invite_codes ic
    LEFT JOIN invite_usage_log iul ON ic.id = iul.invite_code_id
    GROUP BY ic.id
    ORDER BY total_uses DESC;

-- Insert default invite codes for testing
INSERT OR IGNORE INTO invite_codes (code, description, created_by, max_uses, active, notes) VALUES
('BETA-2025-LAUNCH', 'Initial beta launch code - unlimited uses', 'System', -1, 1, 'Primary beta access code'),
('TEAM-MEMBER-001', 'Team member access code - 10 uses', 'System', 10, 1, 'For internal team access'),
('DEMO-ACCESS-123', 'Demo account access - 100 uses', 'System', 100, 1, 'For demonstrations and testing'),
('ADMIN-SETUP-2024', 'Admin setup code - unlimited uses', 'System', -1, 1, 'For system administrators');