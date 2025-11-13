-- Inmate360 - Case Details Table Schema
-- Stores detailed case information scraped from individual inmate pages

CREATE TABLE IF NOT EXISTS inmate_case_details (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inmate_id TEXT NOT NULL,
    docket_number TEXT NOT NULL,
    disposition TEXT,
    sentence TEXT,
    probation_status TEXT,
    charges_json TEXT, -- JSON array of charges
    court_dates_json TEXT, -- JSON array of court dates
    bonds_json TEXT, -- JSON array of bond information
    scrape_time DATETIME,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(inmate_id, docket_number),
    FOREIGN KEY (inmate_id) REFERENCES inmates(inmate_id) ON DELETE CASCADE
);

-- Index for performance
CREATE INDEX IF NOT EXISTS idx_case_details_inmate_id ON inmate_case_details(inmate_id);
CREATE INDEX IF NOT EXISTS idx_case_details_docket ON inmate_case_details(docket_number);
CREATE INDEX IF NOT EXISTS idx_case_details_updated ON inmate_case_details(last_updated);

-- Triggers for automatic timestamp updates
CREATE TRIGGER IF NOT EXISTS update_case_details_timestamp
    AFTER UPDATE ON inmate_case_details
    FOR EACH ROW
    BEGIN
        UPDATE inmate_case_details SET last_updated = CURRENT_TIMESTAMP WHERE id = NEW.id;
    END;