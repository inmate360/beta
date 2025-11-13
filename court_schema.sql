-- Inmate360 Court Case Tracking Database Schema
-- Clayton County Superior Court Data

-- Court Cases Table
CREATE TABLE IF NOT EXISTS court_cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_year INTEGER NOT NULL,
    case_sequence TEXT NOT NULL,
    case_number TEXT UNIQUE NOT NULL,
    inquiry_type TEXT DEFAULT 'Criminal',
    case_type TEXT, -- Added for case type
    defendant_name TEXT NOT NULL,
    offense TEXT,
    filing_date DATE,
    arrest_date DATE,
    indictment_date DATE,
    status TEXT,
    judge TEXT,
    attorney TEXT,
    disposition TEXT,
    disposition_date DATE,
    sentence TEXT,
    bond_amount DECIMAL(10,2),
    next_court_date DATE,
    next_event_time TIME,
    plea TEXT,
    trial_date DATE,
    court TEXT, -- Added for court location/type
    active INTEGER DEFAULT 1,
    linked_inmates INTEGER DEFAULT 0,
    last_known_status TEXT DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Court Charges Table
CREATE TABLE IF NOT EXISTS court_charges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id INTEGER NOT NULL,
    charge_description TEXT NOT NULL,
    charge_type TEXT,
    charge_code TEXT,
    count_number INTEGER,
    plea TEXT,
    verdict TEXT,
    sentence TEXT,
    disposition TEXT,
    disposition_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Events/Docket Table
CREATE TABLE IF NOT EXISTS court_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id INTEGER NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME,
    event_type TEXT,
    event_description TEXT,
    judge TEXT,
    location TEXT,
    outcome TEXT,
    notes TEXT,
    next_event_date DATE,
    next_event_time TIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Scrape Logs
CREATE TABLE IF NOT EXISTS court_scrape_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    cases_found INTEGER DEFAULT 0,
    status TEXT CHECK(status IN ('success', 'error', 'partial')),
    message TEXT,
    error_details TEXT,
    scrape_duration INTEGER, -- seconds
    year_range TEXT, -- e.g., "2023-2025"
    source_url TEXT -- Added for tracking source URLs
);

-- Link table between inmates and court cases
CREATE TABLE IF NOT EXISTS inmate_court_cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inmate_id TEXT NOT NULL,
    case_id INTEGER NOT NULL,
    relationship TEXT DEFAULT 'Defendant',
    confidence_score INTEGER DEFAULT 100, -- 0-100 similarity score
    linked_by TEXT DEFAULT 'auto', -- auto, manual, verified
    verified INTEGER DEFAULT 0, -- 1 if manually verified
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(inmate_id, case_id),
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Case Activity Log
CREATE TABLE IF NOT EXISTS court_case_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id INTEGER NOT NULL,
    activity_type TEXT NOT NULL, -- filing, disposition, event_added, inmate_linked, etc.
    description TEXT,
    activity_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    source TEXT DEFAULT 'scraper',
    FOREIGN KEY (case_id) REFERENCES court_cases(id) ON DELETE CASCADE
);

-- Court Statistics Cache
CREATE TABLE IF NOT EXISTS court_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stat_date DATE NOT NULL,
    total_cases INTEGER DEFAULT 0,
    active_cases INTEGER DEFAULT 0,
    disposed_cases INTEGER DEFAULT 0,
    total_charges INTEGER DEFAULT 0,
    total_events INTEGER DEFAULT 0,
    linked_inmates INTEGER DEFAULT 0,
    avg_bond_amount DECIMAL(10,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stat_date)
);

-- Create comprehensive indexes for performance
CREATE INDEX IF NOT EXISTS idx_court_case_number ON court_cases(case_number);
CREATE INDEX IF NOT EXISTS idx_court_defendant ON court_cases(defendant_name);
CREATE INDEX IF NOT EXISTS idx_court_status ON court_cases(case_status);
CREATE INDEX IF NOT EXISTS idx_court_judge ON court_cases(judge);
CREATE INDEX IF NOT EXISTS idx_court_filing_date ON court_cases(filing_date);
CREATE INDEX IF NOT EXISTS idx_court_disposition ON court_cases(disposition);
CREATE INDEX IF NOT EXISTS idx_court_year ON court_cases(case_year);
CREATE INDEX IF NOT EXISTS idx_court_court ON court_cases(court);
CREATE INDEX IF NOT EXISTS idx_court_case_type ON court_cases(case_type);
CREATE INDEX IF NOT EXISTS idx_court_last_update ON court_cases(updated_at);

CREATE INDEX IF NOT EXISTS idx_court_charges_case ON court_charges(case_id);
CREATE INDEX IF NOT EXISTS idx_court_charges_type ON court_charges(charge_type);
CREATE INDEX IF NOT EXISTS idx_court_charges_disposition ON court_charges(disposition);

CREATE INDEX IF NOT EXISTS idx_court_events_case ON court_events(case_id);
CREATE INDEX IF NOT EXISTS idx_court_events_date ON court_events(event_date);
CREATE INDEX IF NOT EXISTS idx_court_events_type ON court_events(event_type);

CREATE INDEX IF NOT EXISTS idx_court_scrape_time ON court_scrape_logs(scrape_time);
CREATE INDEX IF NOT EXISTS idx_court_scrape_status ON court_scrape_logs(status);
CREATE INDEX IF NOT EXISTS idx_court_scrape_source ON court_scrape_logs(source_url);

CREATE INDEX IF NOT EXISTS idx_inmate_court_inmate ON inmate_court_cases(inmate_id);
CREATE INDEX IF NOT EXISTS idx_inmate_court_case ON inmate_court_cases(case_id);
CREATE INDEX IF NOT EXISTS idx_inmate_court_relationship ON inmate_court_cases(relationship);
CREATE INDEX IF NOT EXISTS idx_inmate_court_confidence ON inmate_court_cases(confidence_score);
CREATE INDEX IF NOT EXISTS idx_inmate_court_verified ON inmate_court_cases(verified);

CREATE INDEX IF NOT EXISTS idx_court_activity_case ON court_case_activity_log(case_id);
CREATE INDEX IF NOT EXISTS idx_court_activity_type ON court_case_activity_log(activity_type);
CREATE INDEX IF NOT EXISTS idx_court_activity_date ON court_case_activity_log(activity_date);

CREATE INDEX IF NOT EXISTS idx_court_stats_date ON court_stats(stat_date);

-- Add triggers for automatic updates
CREATE TRIGGER IF NOT EXISTS update_court_case_timestamp
    AFTER UPDATE ON court_cases
    FOR EACH ROW
    BEGIN
        UPDATE court_cases SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_court_charge_timestamp
    AFTER UPDATE ON court_charges
    FOR EACH ROW
    BEGIN
        UPDATE court_charges SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS update_court_event_timestamp
    AFTER UPDATE ON court_events
    FOR EACH ROW
    BEGIN
        UPDATE court_events SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;

CREATE TRIGGER IF NOT EXISTS log_court_case_activity
    AFTER INSERT ON court_cases
    FOR EACH ROW
    BEGIN
        INSERT INTO court_case_activity_log (case_id, activity_type, description)
        VALUES (NEW.id, 'filing', 'Case filed in court');
    END;

CREATE TRIGGER IF NOT EXISTS log_court_disposition
    AFTER UPDATE ON court_cases
    FOR EACH ROW
    WHEN NEW.disposition != OLD.disposition AND NEW.disposition IS NOT NULL
    BEGIN
        INSERT INTO court_case_activity_log (case_id, activity_type, description)
        VALUES (NEW.id, 'disposition', 'Case disposition: ' || NEW.disposition);
    END;

-- Views for common queries
CREATE VIEW IF NOT EXISTS active_court_cases AS
    SELECT * FROM court_cases
    WHERE active = 1 AND case_status NOT LIKE '%Disposed%'
    ORDER BY filing_date DESC;

CREATE VIEW IF NOT EXISTS recent_filings AS
    SELECT * FROM court_cases
    WHERE filing_date >= date('now', '-30 days')
    ORDER BY filing_date DESC;

CREATE VIEW IF NOT EXISTS cases_with_inmate_links AS
    SELECT c.*, COUNT(icc.id) as linked_inmates
    FROM court_cases c
    LEFT JOIN inmate_court_cases icc ON c.id = icc.case_id
    GROUP BY c.id
    HAVING linked_inmates > 0
    ORDER BY linked_inmates DESC;

CREATE VIEW IF NOT EXISTS court_cases_by_judge AS
    SELECT judge, COUNT(*) as case_count,
           AVG(CASE WHEN bond_amount > 0 THEN bond_amount ELSE NULL END) as avg_bond
    FROM court_cases
    WHERE judge IS NOT NULL AND judge != ''
    GROUP BY judge
    ORDER BY case_count DESC;