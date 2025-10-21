-- ===========================================================
--  labki_content_repo: top-level content repository
-- ===========================================================
CREATE TABLE labki_content_repo (
  content_repo_id        INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_url        TEXT NOT NULL,    -- canonical repository URL
  content_repo_name       TEXT,             -- manifest-defined or inferred name
  source_ref              TEXT,             -- branch, tag, or commit actually used
  manifest_path           TEXT,             -- path to manifest file
  manifest_hash           TEXT,             -- hash of manifest contents
  manifest_last_parsed    INTEGER,          -- timestamp of last manifest parse
  last_commit             TEXT,             -- HEAD commit hash from last successful pull
  created_at              INTEGER NOT NULL,
  updated_at              INTEGER NOT NULL,
  UNIQUE (content_repo_url, source_ref)
);

-- ===========================================================
--  labki_pack: per-pack info within a content repo
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_pack (
  pack_id INTEGER PRIMARY KEY AUTOINCREMENT,
  content_repo_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  version TEXT,
  source_ref TEXT,
  source_commit TEXT,
  installed_at INTEGER,
  installed_by INTEGER,
  updated_at INTEGER,
  status TEXT CHECK(status IN ('installed','pending','removed','error')) DEFAULT 'installed',
  UNIQUE (content_repo_id, name),
  FOREIGN KEY (content_repo_id) REFERENCES labki_content_repo (content_repo_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  FOREIGN KEY (installed_by) REFERENCES user (user_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
);

-- ===========================================================
--  labki_page: per-page info within a pack (includes name mapping)
-- ===========================================================
CREATE TABLE IF NOT EXISTS labki_page (
  page_id INTEGER PRIMARY KEY AUTOINCREMENT,
  pack_id INTEGER NOT NULL,
  name TEXT NOT NULL,                 -- original name in the pack
  final_title TEXT NOT NULL,          -- actual wiki page title (after rename/prefix)
  page_namespace INTEGER NOT NULL,    -- MediaWiki namespace ID
  wiki_page_id INTEGER,               -- link to core 'page' table
  last_rev_id INTEGER,                -- link to latest revision installed
  content_hash TEXT,                  -- hash of installed content for drift detection
  created_at INTEGER,
  updated_at INTEGER,
  UNIQUE (pack_id, name),
  UNIQUE (pack_id, final_title),
  FOREIGN KEY (pack_id) REFERENCES labki_pack (pack_id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);

-- ===========================================================
--  Helpful indexes
-- ===========================================================

-- Core relational indexes
CREATE INDEX idx_labki_pack_repo           ON labki_pack (content_repo_id);
CREATE INDEX idx_labki_page_pack           ON labki_page (pack_id);
CREATE INDEX idx_labki_page_final_title    ON labki_page (final_title);
CREATE INDEX idx_labki_page_wiki_page_id   ON labki_page (wiki_page_id);

-- New helpful indexes for multi-ref repos
CREATE INDEX idx_labki_repo_url_ref        ON labki_content_repo (content_repo_url, source_ref);
CREATE INDEX idx_labki_repo_name           ON labki_content_repo (content_repo_name);
CREATE INDEX idx_labki_repo_last_commit    ON labki_content_repo (last_commit);
