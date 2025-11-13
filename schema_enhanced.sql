
-- ENHANCED SCHEMA FOR COURT CASE INTEGRATION

-- New Court Cases Table
CREATE TABLE IF NOT EXISTS court_cases (
id INTEGER PRIMARY KEY AUTOINCREMENT,
case_number TEXT UNIQUE NOT NULL,
case_type TEXT, -- CR (Criminal), CV (Civil), etc.
case_year TEXT,
filing_date TEXT,
court_type TEXT, -- Superior, State, Magistrate
case_status TEXT, -- Pending, Disposed, Closed, etc.
disposition TEXT,
disposition_date TEXT,
judge_name TEXT,
prosecuting_attorney TEXT,
defense_attorney TEXT,
arresting_officer TEXT,
next_court_date TEXT,
court_room TEXT,
bond_amount TEXT,
bond_type TEXT,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Inmate-Court Case Link Table (Many-to-Many relationship)
CREATE TABLE IF NOT EXISTS inmate_court_cases (
id INTEGER PRIMARY KEY AUTOINCREMENT,
inmate_id TEXT NOT NULL,
case_number TEXT NOT NULL,
defendant_name TEXT, -- Name as appears in court records
case_role TEXT, -- Defendant, Co-Defendant, Witness, etc.
linked_date DATETIME DEFAULT CURRENT_TIMESTAMP,
link_confidence REAL DEFAULT 1.0, -- Confidence score for fuzzy matching
link_method TEXT, -- manual, auto_exact, auto_fuzzy, etc.
verified INTEGER DEFAULT 0, -- Manual verification flag
notes TEXT,
FOREIGN KEY (inmate_id) REFERENCES inmates(inmate_id) ON DELETE CASCADE,
FOREIGN KEY (case_number) REFERENCES court_cases(case_number) ON DELETE CASCADE,
UNIQUE(inmate_id, case_number)
);

-- Court Case Charges (separate from inmate charges)
CREATE TABLE IF NOT EXISTS court_case_charges (
id INTEGER PRIMARY KEY AUTOINCREMENT,
case_number TEXT NOT NULL,
charge_description TEXT NOT NULL,
charge_code TEXT,
charge_count INTEGER,
charge_degree TEXT, -- Felony, Misdemeanor, etc.
charge_status TEXT, -- Pending, Guilty, Not Guilty, Dismissed, etc.
plea TEXT,
sentence TEXT,
sentence_date TEXT,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (case_number) REFERENCES court_cases(case_number) ON DELETE CASCADE
);

-- Court Events/Hearings Table
CREATE TABLE IF NOT EXISTS court_events (
id INTEGER PRIMARY KEY AUTOINCREMENT,
case_number TEXT NOT NULL,
event_type TEXT, -- Arraignment, Preliminary Hearing, Trial, Sentencing, etc.
event_date TEXT,
event_time TEXT,
event_status TEXT, -- Scheduled, Completed, Continued, Cancelled
event_result TEXT,
court_room TEXT,
judge_name TEXT,
notes TEXT,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (case_number) REFERENCES court_cases(case_number) ON DELETE CASCADE
);

-- Court Scrape Logs
CREATE TABLE IF NOT EXISTS court_scrape_logs (
id INTEGER PRIMARY KEY AUTOINCREMENT,
scrape_time DATETIME DEFAULT CURRENT_TIMESTAMP,
cases_found INTEGER DEFAULT 0,
cases_new INTEGER DEFAULT 0,
cases_updated INTEGER DEFAULT 0,
search_parameters TEXT, -- JSON or text of search params used
status TEXT,
message TEXT,
error_details TEXT
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_court_case_number ON court_cases(case_number);
CREATE INDEX IF NOT EXISTS idx_court_case_status ON court_cases(case_status);
CREATE INDEX IF NOT EXISTS idx_court_case_date ON court_cases(filing_date);
CREATE INDEX IF NOT EXISTS idx_inmate_court_link ON inmate_court_cases(inmate_id, case_number);
CREATE INDEX IF NOT EXISTS idx_court_charges_case ON court_case_charges(case_number);
CREATE INDEX IF NOT EXISTS idx_court_events_case ON court_events(case_number);
CREATE INDEX IF NOT EXISTS idx_court_events_date ON court_events(event_date);
